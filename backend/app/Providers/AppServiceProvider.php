<?php

namespace App\Providers;

use App\Services\JwtTokenService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('auth-login', static function (Request $request): array {
            $email = mb_strtolower(trim((string) $request->input('email')));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)
                    ->by('login:'.hash('sha256', $email.'|'.$ip))
                    ->response(static fn (Request $request, array $headers) => self::tooManyRequests($headers)),
                Limit::perMinute(120)
                    ->by('login-ip:'.$ip)
                    ->response(static fn (Request $request, array $headers) => self::tooManyRequests($headers)),
            ];
        });

        RateLimiter::for('auth-refresh', static function (Request $request): array {
            $userId = trim((string) $request->input('userId'));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(10)
                    ->by('refresh-user:'.($userId !== '' ? $userId : 'missing:'.$ip))
                    ->response(static fn (Request $request, array $headers) => self::tooManyRequests($headers)),
                Limit::perMinute(300)
                    ->by('refresh-ip:'.$ip)
                    ->response(static fn (Request $request, array $headers) => self::tooManyRequests($headers)),
            ];
        });

        RateLimiter::for('authenticated-api', static function (Request $request): Limit {
            $identity = $request->attributes->get('apiIdentity');
            $userId = is_array($identity) ? (string) ($identity['userId'] ?? '') : '';

            return Limit::perMinute(120)
                ->by('authenticated-api:'.($userId !== '' ? $userId : (string) $request->ip()))
                ->response(static fn (Request $request, array $headers) => self::tooManyRequests($headers));
        });

        RateLimiter::for('notebook-chat', static function (Request $request): array {
            $identity = $request->attributes->get('apiIdentity');
            $userId = is_array($identity) ? (string) ($identity['userId'] ?? '') : '';
            if ($userId === '' && ($bearerToken = $request->bearerToken())) {
                try {
                    $claims = app(JwtTokenService::class)->verify(
                        $bearerToken,
                        (string) config('auth.jwt.access_secret')
                    );
                    $userId = (string) ($claims['sub'] ?? '');
                } catch (\Throwable) {
                    // Invalid tokens are rejected by authentication middleware.
                }
            }
            $clientRequestId = strtolower(trim((string) $request->input('clientRequestId')));
            $isTicketRetry = $userId !== '' &&
                $clientRequestId !== '' &&
                DB::table('ai_notebook_chat_tickets')
                    ->where('user_id', $userId)
                    ->where('client_request_id', $clientRequestId)
                    ->exists();

            if ($isTicketRetry) {
                return [
                    Limit::perMinute(30)
                        ->by('notebook-chat-retry:'.$userId.':'.$clientRequestId)
                        ->response(static fn (Request $request, array $headers) => self::tooManyRequests($headers)),
                ];
            }

            return [
                Limit::perMinute(6)
                    ->by('notebook-chat:'.($userId !== '' ? $userId : (string) $request->ip()))
                    ->response(static fn (Request $request, array $headers) => self::tooManyRequests($headers)),
            ];
        });

        RateLimiter::for('test-status', static function (Request $request): array {
            $identity = $request->attributes->get('apiIdentity');
            $userId = is_array($identity) ? (string) ($identity['userId'] ?? '') : '';

            return [
                Limit::perMinute(6)
                    ->by('test-status:'.$userId.':'.(string) $request->route('id'))
                    ->response(static fn (Request $request, array $headers) => self::tooManyRequests($headers)),
            ];
        });
    }

    /**
     * Keep rate-limit errors in the compatibility JSON format and provide
     * Retry-After so clients can back off without guessing.
     *
     * @param array<string, string> $headers
     */
    private static function tooManyRequests(array $headers): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'statusCode' => 429,
            'message' => 'Too many requests. Please wait and try again.',
            'error' => 'Too Many Requests',
        ], 429, $headers);
    }
}
