<?php

namespace EasyCo\Shipping;

use EasyCo\Shipping\Exceptions\InvalidShippingMethodException;

/**
 * The courier GROUP of a method (shipping stage 5f), in one small place used by the admin and the quote API.
 *
 * A courier is a plain single-line display name ("Еконт", "Speedy"). Two names are the SAME group when their
 * group keys are equal — the trimmed, lower-cased name — so "Еконт" and "еконт " group together; the DISPLAY name of
 * a group is the first one met in method order. Methods without a courier are not a group of their own in the
 * ordinary sense: they are collected, in order, in ONE trailing entry whose courier is null.
 */
final class ShippingCourier
{
    public const MAX_LENGTH = 100;

    /**
     * The stored courier: trimmed, NULL when empty.
     *
     * @throws InvalidShippingMethodException too long, or not a plain single line
     */
    public static function normalize(?string $courier): ?string
    {
        if ($courier === null) {
            return null;
        }

        $courier = trim($courier);

        if ($courier === '') {
            return null;
        }

        if (mb_strlen($courier) > self::MAX_LENGTH) {
            throw InvalidShippingMethodException::courierTooLong(self::MAX_LENGTH);
        }

        if (preg_match('/[\x00-\x1F\x7F-\x9F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $courier) === 1) {
            throw InvalidShippingMethodException::courierNotPlain();
        }

        return $courier;
    }

    /** The group key: trimmed and lower-cased (multibyte, so Cyrillic works); null for no courier. */
    public static function key(?string $courier): ?string
    {
        if ($courier === null || trim($courier) === '') {
            return null;
        }

        return mb_strtolower(trim($courier));
    }

    /**
     * Groups items by courier, keeping their order: one entry per courier in order of its first item, the items
     * without a courier collected in ONE trailing entry with courier null. The courier of an entry is its first
     * item's name, trimmed.
     *
     * @template T
     *
     * @param  iterable<T>  $items
     * @param  \Closure(T): ?string  $courierOf
     * @return list<array{courier: ?string, items: list<T>}>
     */
    public static function group(iterable $items, \Closure $courierOf): array
    {
        $groups = [];
        $ungrouped = [];

        foreach ($items as $item) {
            $courier = $courierOf($item);
            $key = self::key($courier);

            if ($key === null) {
                $ungrouped[] = $item;

                continue;
            }

            $groups[$key] ??= ['courier' => trim((string) $courier), 'items' => []];
            $groups[$key]['items'][] = $item;
        }

        $list = array_values($groups);

        if ($ungrouped !== []) {
            $list[] = ['courier' => null, 'items' => $ungrouped];
        }

        return $list;
    }

    /** "Courier – name", or just the name when the method has no courier (the free-shipping hint's display name). */
    public static function displayName(?string $courier, string $name): string
    {
        $courier = $courier === null ? null : trim($courier);

        return $courier === null || $courier === '' ? $name : $courier.' – '.$name;
    }
}
