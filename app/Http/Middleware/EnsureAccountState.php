<?php

namespace App\Http\Middleware;

use App\Services\TokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureAccountState
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->routeIs('auth.logout')) {
            return $next($request);
        }
        $token = $request->bearerToken();
        $requiresAuth = collect($request->route()->gatherMiddleware())->contains(
            fn ($middleware) => $middleware === 'auth' || str_starts_with($middleware, 'auth:')
        );
        if ($requiresAuth && !$token) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        $user = null;
        if ($token) {
            $user = app(TokenService::class)->authenticate($token);
            if (!$user) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }
            Auth::guard('api')->setUser($user);
        }
        if ($user && !$user->active) {
            return response()->json(['message' => 'Account unavailable'], 403);
        }
        if ($user && $user->must_change_password && !$request->routeIs(
            'profile.view', 'profile.update', 'auth.logout', 'auth.refresh')) {
            return response()->json(['message' => 'Password change required'], 403);
        }
        return $next($request);
    }
}
