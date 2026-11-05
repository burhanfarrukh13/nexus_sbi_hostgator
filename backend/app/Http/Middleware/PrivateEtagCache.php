<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrivateEtagCache
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        if (!$request->isMethod('GET') || $response->getStatusCode() !== 200) {
            return $response;
        }

        $etag = '"'.hash('sha256', (string) $response->getContent()).'"';
        $headers = [
            'Cache-Control' => 'private, max-age=30, must-revalidate',
            'ETag' => $etag,
            'Vary' => 'Authorization',
        ];
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, $headers);
        }

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
