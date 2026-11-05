<?php

namespace App\Http\Controllers;

use App\Services\JwtTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

class AuthController
{
    public function __construct(private readonly JwtTokenService $tokens)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);
        $input = $request->validate([
            'email' => ['required', 'string', 'email', 'min:3', 'max:254'],
            'password' => ['required', 'string', 'min:7', 'max:200'],
        ]);
        $email = $input['email'];
        $user = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user || $user->disabled_at !== null || !Hash::check($input['password'], $user->password)) {
            throw new UnauthorizedHttpException('', 'Invalid credentials');
        }

        return response()->json(
            DB::transaction(fn (): array => $this->rotateTokens($user, true)),
            201
        );
    }

    public function refresh(Request $request): JsonResponse
    {
        $input = $request->validate([
            'userId' => ['required', 'string', 'uuid'],
            'refreshToken' => ['required', 'string', 'min:20', 'max:4096'],
        ]);

        try {
            $claims = $this->tokens->verify($input['refreshToken'], (string) config('auth.jwt.refresh_secret'));
        } catch (Throwable) {
            throw new UnauthorizedHttpException('', 'Invalid refresh token');
        }

        if (($claims['typ'] ?? null) !== 'refresh' || !is_string($claims['sub'] ?? null) ||
            !is_string($claims['jti'] ?? null) || $claims['sub'] !== $input['userId']) {
            throw new UnauthorizedHttpException('', 'Refresh token user mismatch');
        }

        $result = DB::transaction(function () use ($claims, $input): array {
            $user = DB::table('users')->where('id', $claims['sub'])->lockForUpdate()->first();
            if (!$user || $user->disabled_at !== null) {
                return ['error' => 'User not found'];
            }
            $digest = hash('sha256', $input['refreshToken']);
            if (is_string($user->refreshTokenHash) && hash_equals($user->refreshTokenHash, $digest)) {
                return $this->rotateTokens($user, false);
            }
            if (is_string($user->previousRefreshTokenHash) &&
                hash_equals($user->previousRefreshTokenHash, $digest)) {
                DB::table('users')->where('id', $user->id)->update([
                    'refreshTokenHash' => null,
                    'previousRefreshTokenHash' => null,
                    'refreshCounter' => (int) $user->refreshCounter + 1000,
                    'updatedAt' => now(),
                ]);
                return ['error' => 'Refresh token revoked'];
            }

            return ['error' => 'Invalid refresh token'];
        });

        if (isset($result['error'])) {
            throw new UnauthorizedHttpException('', $result['error']);
        }

        return response()->json($result, 201);
    }

    public function signOut(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('apiIdentity');
        $userId = (string) $identity['userId'];
        $changes = [
            'previousRefreshTokenHash' => DB::raw('refreshTokenHash'),
            'refreshTokenHash' => hash('sha256', ''),
            'refreshCounter' => DB::raw('refreshCounter + 1'),
            'updatedAt' => now(),
        ];
        DB::table('users')->where('id', $userId)->update($changes);

        return response()->json(['success' => true], 201);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->attributes->get('apiIdentity'));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $input = $request->validate([
            'currentPassword' => ['required', 'string', 'min:1', 'max:200'],
            'newPassword' => ['required', 'string', 'min:12', 'max:200', 'different:currentPassword'],
        ]);
        $identity = $request->attributes->get('apiIdentity');
        $user = DB::table('users')->where('id', $identity['userId'])->first();
        if (!$user || !Hash::check($input['currentPassword'], $user->password)) {
            throw new UnauthorizedHttpException('', 'Current password is incorrect');
        }

        DB::table('users')->where('id', $user->id)->update([
            'password' => Hash::make($input['newPassword']),
            'must_reset_password' => false,
            'refreshTokenHash' => null,
            'previousRefreshTokenHash' => null,
            'refreshCounter' => (int) $user->refreshCounter + 1,
            'updatedAt' => now(),
        ]);

        return response()->json(['success' => true], 201);
    }

    private function rotateTokens(object $user, bool $lock): array
    {
        $user = $lock
            ? DB::table('users')->where('id', $user->id)->lockForUpdate()->first()
            : $user;
        if (!$user || $user->disabled_at !== null) {
            throw new UnauthorizedHttpException('', 'User account is disabled or no longer exists');
        }
        $permissions = DB::table('user_role_assignments')
            ->where('userId', $user->id)
            ->orderBy('permission')
            ->pluck('permission')
            ->all();
        $refreshToken = $this->tokens->issue([
            'sub' => $user->id,
            'typ' => 'refresh',
            'jti' => (string) \Illuminate\Support\Str::uuid(),
        ], (string) config('auth.jwt.refresh_secret'), (string) config('auth.jwt.refresh_expiry'));
        $counter = (int) $user->refreshCounter + 1;
        DB::table('users')->where('id', $user->id)->update([
            'previousRefreshTokenHash' => $user->refreshTokenHash,
            'refreshTokenHash' => hash('sha256', $refreshToken),
            'refreshCounter' => $counter,
            'updatedAt' => now(),
        ]);

        $accessToken = $this->tokens->issue([
            'sub' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'adminPermissions' => $permissions,
            'mustResetPassword' => (bool) $user->must_reset_password,
            'sessionVersion' => $counter,
            'jti' => (string) \Illuminate\Support\Str::uuid(),
        ], (string) config('auth.jwt.access_secret'), (string) config('auth.jwt.access_expiry'));

        return [
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'role' => $user->role,
                'studentId' => $user->studentId,
                'adminPermissions' => $permissions,
                'mustResetPassword' => (bool) $user->must_reset_password,
            ],
        ];
    }

    private function durationSeconds(string $duration): int
    {
        if (!preg_match('/^([1-9][0-9]*)(s|m|h|d)$/', $duration, $match)) {
            return 3600;
        }
        $unit = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$match[2]];

        return (int) $match[1] * $unit;
    }
}
