<?php

namespace EasyCo\Shipping\Carrier;

/**
 * Removes what must never be stored from a request or response snapshot (courier-integration-design.md §10.4), at any depth:
 * the keys `password`, `userName`, `client_secret`, `access_token`, `Authorization` (any letter case). The shipments domain (COUR-3a)
 * applies it to every snapshot; it exists now because the Speedy adapter puts `userName` / `password` in its request bodies.
 */
final class CourierRedactor
{
    public const KEYS = ['password', 'username', 'client_secret', 'access_token', 'authorization'];

    public const MASK = '[removed]';

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::KEYS, true)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
