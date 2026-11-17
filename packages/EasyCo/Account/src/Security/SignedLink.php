<?php

namespace EasyCo\Account\Security;

/**
 * ACC-1: the signed, expiring parameters of an account link (verify, confirm a new email): `id`, `expires` (unix seconds), optional `token`, and `signature` =
 * HMAC-SHA256 over purpose|id|expires|token with a key derived from APP_KEY. The storefront page forwards them as they are to the API, which checks them here;
 * the link is therefore not tied to one URL path (Laravel's own signed URLs sign the full URL of ONE route). The purpose is part of the signature: a verify link
 * is not a valid email-change link.
 */
final class SignedLink
{
    /** @return array{id: string, expires: int, signature: string, token?: string} */
    public static function params(string $purpose, string $id, int $ttlSeconds, ?string $token = null): array
    {
        $expires = self::now() + $ttlSeconds;
        $params = ['id' => $id, 'expires' => $expires, 'signature' => self::sign($purpose, $id, $expires, $token ?? '')];

        return $token === null ? $params : $params + ['token' => $token];
    }

    public static function valid(string $purpose, string $id, mixed $expires, mixed $signature, string $token = ''): bool
    {
        if (! is_string($signature) || ! (is_int($expires) || (is_string($expires) && ctype_digit($expires)))) {
            return false;
        }

        return (int) $expires >= self::now() && hash_equals(self::sign($purpose, $id, (int) $expires, $token), $signature);
    }

    /** the app's clock (Carbon), so a test can travel in time */
    private static function now(): int
    {
        return \Illuminate\Support\Carbon::now()->getTimestamp();
    }

    private static function sign(string $purpose, string $id, int $expires, string $token): string
    {
        return hash_hmac('sha256', $purpose.'|'.$id.'|'.$expires.'|'.$token, hash('sha256', 'account-link|'.(string) config('app.key'), true));
    }
}
