<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\CorsAllowlist;
use App\Http\Middleware\PrivateEtagCache;
use App\Http\Middleware\RequireAdminPermissions;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([__DIR__.'/../app/Console/Commands'])
    ->withMiddleware(function (Middleware $middleware): void {
        $isStudentRequest = static fn (Request $request): bool =>
            $request->is('api/students') || $request->is('api/students/*');
        $preserveDtoStrings = static fn (Request $request): bool =>
            $isStudentRequest($request) ||
            $request->is('api/users') ||
            $request->is('api/users/*') ||
            $request->is('api/student-queries') ||
            $request->is('api/student-queries/*') ||
            $request->is('api/tests') ||
            $request->is('api/tests/*');
        $middleware->trimStrings(except: [$preserveDtoStrings]);
        $middleware->convertEmptyStringsToNull(except: [$preserveDtoStrings]);
        $middleware->alias([
            'auth.compat' => AuthenticateApiToken::class,
            'permission.compat' => RequireAdminPermissions::class,
            'cache.private-etag' => PrivateEtagCache::class,
        ]);
        $middleware->append(CorsAllowlist::class);
        $middleware->append(AddSecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception): bool => $request->is('api/*')
        );
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }
            $messages = [];
            foreach ($exception->errors() as $fieldErrors) {
                $messages = array_merge($messages, $fieldErrors);
            }

            return response()->json([
                'statusCode' => 400,
                'message' => $messages,
                'error' => 'Bad Request',
            ], 400);
        });
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }
            $status = $exception->getStatusCode();

            return response()->json([
                'statusCode' => $status,
                'message' => $exception->getMessage() !== '' ? $exception->getMessage() : Response::$statusTexts[$status] ?? 'Error',
                'error' => Response::$statusTexts[$status] ?? 'Error',
            ], $status, $exception->getHeaders());
        });
    })->create();
