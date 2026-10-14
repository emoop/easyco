<?php

namespace App\Http;

/**
 * The `TRUSTED_PROXIES` environment value as the list of proxies Laravel's TrustProxies middleware may believe
 * (order-context-design.md section 7.4): comma-separated IP addresses and CIDR ranges, nothing else.
 *
 *  - EMPTY OR UNSET = TRUST NONE (an empty list): `$request->ip()` is then the direct socket peer, and a forged `X-Forwarded-For` is
 *    never believed. This is the safe default of every installation until its operator names the proxies in front of the app.
 *  - NEVER A WILDCARD: `*` and `**` (trust whoever is calling) are dropped, because the header is client-spoofable and trusting it from
 *    anyone records whatever address an attacker likes on every order. So is anything that is not an IP or a CIDR range (a hostname, a
 *    typo): an entry that cannot be read is ignored, never guessed at.
 *
 * Read by config/trustedproxy.php, which is where the framework's own middleware looks (`config('trustedproxy.proxies')`).
 */
final class TrustedProxyList
{
    /** @return list<string> */
    public static function parse(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $proxies = [];

        foreach (explode(',', $value) as $entry) {
            $entry = trim($entry);

            if ($entry !== '' && self::isIpOrCidr($entry)) {
                $proxies[] = $entry;
            }
        }

        return array_values(array_unique($proxies));
    }

    private static function isIpOrCidr(string $entry): bool
    {
        [$address, $prefix] = array_pad(explode('/', $entry, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        $max = str_contains($address, ':') ? 128 : 32;

        // A /0 would trust the whole internet: refused like the wildcard it is.
        return ctype_digit($prefix) && (int) $prefix >= 1 && (int) $prefix <= $max;
    }
}
