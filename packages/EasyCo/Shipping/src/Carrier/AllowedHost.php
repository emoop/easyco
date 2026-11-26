<?php

namespace EasyCo\Shipping\Carrier;

/**
 * A bare, lower-case hostname that equals or sits under one of a fixed list of domains; nothing else passes. No scheme,
 * port, path, query, userinfo, IP address or trailing dot: whatever a settings value looks like, only a plain DNS name
 * under an allowed domain can ever become an outbound host.
 */
final class AllowedHost
{
    /** @param list<string> $suffixes registrable domains, e.g. `boxnow.bg` */
    public static function clean(string $value, array $suffixes): ?string
    {
        $host = strtolower(trim($value));

        if ($host === '' || strlen($host) > 253 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/D', $host) !== 1) {
            return null;
        }

        foreach ($suffixes as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return $host;
            }
        }

        return null;
    }
}
