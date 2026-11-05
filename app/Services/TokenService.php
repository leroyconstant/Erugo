<?php

namespace App\Services;

use App\Models\User;
use Symfony\Component\HttpFoundation\Cookie;

class TokenService
{
    public function authenticate(?string $token, array $kinds = ['access']): ?User
    {
        if (!$token) {
            return null;
        }
        try {
            $jwt = clone app('tymon.jwt');
            $payload = $jwt->setToken($token)->getPayload();
            $user = User::find($payload->get('sub'));
            $version = $payload->get('sv');
            if (!$user || !$user->active || !is_string($version)
                || !hash_equals($user->sessionVersion(), $version)
                || !in_array($payload->get('kind'), $kinds, true)) {
                return null;
            }
            return $user;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function issue(User $user, string $kind = 'access', int $minutes = 60): string
    {
        abort_unless($user->active, 403, 'Account unavailable');
        $jwt = app('tymon.jwt');
        $ttl = $jwt->factory()->getTTL();
        $claims = $jwt->getCustomClaims();
        try {
            $jwt->factory()->setTTL($minutes);
            return $jwt->claims(['kind' => $kind])->fromUser($user);
        } finally {
            $jwt->factory()->setTTL($ttl);
            $jwt->claims($claims);
        }
    }

    public function refreshCookie(User $user): Cookie
    {
        return cookie('refresh_token', $this->issue($user, 'refresh', 1440), 1440,
            '/', null, (bool) config('erugo.secure_cookies', true), true, false, 'lax');
    }

    public function forgetCookie(): Cookie
    {
        return cookie('refresh_token', '', -1, '/', null,
            (bool) config('erugo.secure_cookies', true), true, false, 'lax');
    }

    public function invalidate(?string $token): void
    {
        if ($token) {
            try {
                $jwt = clone app('tymon.jwt');
                $jwt->setToken($token)->invalidate();
            } catch (\Exception $e) {
                // Already expired or invalid; the response still clears the cookie.
            }
        }
    }
}
