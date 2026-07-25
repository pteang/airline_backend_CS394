<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to internal users. Optionally narrows to specific roles,
 * e.g. ->middleware('internal:admin,manager').
 */
class EnsureInternalUser
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if ($request->attributes->get('auth_type') !== 'internal') {
            return response()->json(['message' => 'Staff access required.'], 403);
        }

        if (! empty($roles)) {
            $user = $request->attributes->get('auth_user');
            if (! in_array($user->role->value, $roles, true)) {
                return response()->json(['message' => 'Insufficient role.'], 403);
            }
        }

        return $next($request);
    }
}
