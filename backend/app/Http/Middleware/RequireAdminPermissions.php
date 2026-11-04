<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminPermissions
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $identity = $request->attributes->get('apiIdentity');
        if (!$identity) {
            return response()->json(['statusCode' => 401, 'message' => 'Unauthorized'], 401);
        }

        $required = explode('|', $permissions);
        if ($required === [] || in_array('', $required, true)) {
            return response()->json([
                'statusCode' => 403,
                'message' => 'Admin permission metadata is required for this route',
                'error' => 'Forbidden',
            ], 403);
        }

        $granted = $identity['adminPermissions'] ?? [];
        if (!in_array('admin', $granted, true) && count(array_intersect($required, $granted)) === 0) {
            return response()->json([
                'statusCode' => 403,
                'message' => 'Missing required permission(s): '.implode(', ', $required),
                'error' => 'Forbidden',
            ], 403);
        }

        return $next($request);
    }
}
