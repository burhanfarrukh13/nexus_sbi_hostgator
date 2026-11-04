<?php

namespace App\Http\Middleware;

use App\Services\JwtTokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateApiToken
{
    public function __construct(private readonly JwtTokenService $tokens)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return response()->json(['statusCode' => 401, 'message' => 'Unauthorized'], 401);
        }

        try {
            $payload = $this->tokens->verify($matches[1], (string) config('auth.jwt.access_secret'));
        } catch (Throwable) {
            return response()->json(['statusCode' => 401, 'message' => 'Invalid access token'], 401);
        }

        if (($payload['typ'] ?? null) === 'refresh' ||
            !is_string($payload['sub'] ?? null) ||
            !is_string($payload['jti'] ?? null) ||
            !is_int($payload['sessionVersion'] ?? null)) {
            return response()->json(['statusCode' => 401, 'message' => 'Malformed token'], 401);
        }

        $user = DB::table('users')->where('id', $payload['sub'])->first();
        if (!$user || $user->disabled_at !== null ||
            (int) $user->refreshCounter !== $payload['sessionVersion']) {
            return response()->json(['statusCode' => 401, 'message' => 'Session has been revoked'], 401);
        }

        $identity = [
            'userId' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'adminPermissions' => is_array($payload['adminPermissions'] ?? null)
                ? $payload['adminPermissions']
                : [],
            'mustResetPassword' => (bool) $user->must_reset_password,
            'jti' => $payload['jti'],
        ];

        if ($identity['mustResetPassword'] && !$this->isPasswordResetRequest($request)) {
            return response()->json([
                'statusCode' => 403,
                'code' => 'PASSWORD_RESET_REQUIRED',
                'message' => 'Change your password before using this service.',
            ], 403);
        }

        $request->attributes->set('apiIdentity', $identity);

        return $next($request);
    }

    private function isPasswordResetRequest(Request $request): bool
    {
        return ($request->isMethod('GET') && $request->is('api/auth/me')) ||
            ($request->isMethod('POST') && $request->is('api/auth/signout')) ||
            ($request->isMethod('POST') && $request->is('api/auth/change-password'));
    }
}
