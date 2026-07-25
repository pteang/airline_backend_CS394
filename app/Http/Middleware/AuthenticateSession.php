<?php

namespace App\Http\Middleware;

use App\Models\InternalUser;
use App\Models\User;
use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the bearer token to a session and attaches the authenticated party
 * to the request. The session lookup is cached in Redis (keyed by token) so
 * authenticated requests avoid a Postgres round-trip on every call; Postgres
 * remains the source of truth and the cache is invalidated on logout.
 */
class AuthenticateSession
{
    /** How long a resolved session stays cached in Redis. */
    private const CACHE_TTL_SECONDS = 900;

    public static function cacheKey(string $token): string
    {
        return 'session_token:'.hash('sha256', $token);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Redis fast path: {type, id, expires_at} resolved from the session store.
        $resolved = Cache::remember(self::cacheKey($token), self::CACHE_TTL_SECONDS, function () use ($token) {
            $session = UserSession::where('session_token', $token)
                ->where('is_active', true)
                ->first();

            if (! $session) {
                return null;
            }

            return [
                'type' => $session->user_id ? 'passenger' : 'internal',
                'id' => $session->user_id ?? $session->internal_user_id,
                'session_id' => $session->id,
                'expires_at' => optional($session->expires_at)->toIso8601String(),
            ];
        });

        if (! $resolved) {
            Cache::forget(self::cacheKey($token));

            return response()->json(['message' => 'Token invalid or expired.'], 401);
        }

        if ($resolved['expires_at'] && now()->greaterThan($resolved['expires_at'])) {
            Cache::forget(self::cacheKey($token));

            return response()->json(['message' => 'Token invalid or expired.'], 401);
        }

        $owner = $resolved['type'] === 'passenger'
            ? User::find($resolved['id'])
            : InternalUser::find($resolved['id']);

        if (! $owner || ! $owner->is_active) {
            return response()->json(['message' => 'Account is inactive.'], 403);
        }

        $request->attributes->set('auth_session_id', $resolved['session_id']);
        $request->attributes->set('auth_token', $token);
        $request->attributes->set('auth_user', $owner);
        $request->attributes->set('auth_type', $resolved['type']);
        $request->setUserResolver(fn () => $owner);

        return $next($request);
    }
}
