<?php

namespace App\Services;

use EasyCo\Shipping\ShippingCourier;

/**
 * The `groups` of the quote response (shipping stage 5f): the offered methods grouped by courier, so a client can ask
 * the customer for the courier first and the delivery type second. Built from the FINAL list of methods — after the
 * `shipping.quotes` filter and after the handles were issued — so a method a filter removed is in no group.
 *
 * One entry per courier in order of the first method that belongs to it (the group key is ShippingCourier::key(),
 * the name shown is the first one's); methods without a courier are collected, in order, in ONE trailing entry with
 * `courier: null`. `methods` are ids, in method order; `from_minor` is the lowest price among the AVAILABLE methods
 * of the group, null when none is available. Nothing is priced here: the amounts are the methods' own.
 */
final class QuoteGroups
{
    /**
     * @param  list<MethodQuote>  $methods
     * @return list<array{courier: ?string, methods: list<string>, from_minor: ?int, currency: string}>
     */
    public static function build(array $methods, string $currency): array
    {
        $groups = [];

        foreach (ShippingCourier::group($methods, static fn (MethodQuote $method): ?string => $method->courier) as $group) {
            $prices = [];

            foreach ($group['items'] as $method) {
                if ($method->amountMinor !== null) {
                    $prices[] = $method->amountMinor;
                }
            }

            $groups[] = [
                'courier' => $group['courier'],
                'methods' => array_map(static fn (MethodQuote $method): string => $method->methodId, $group['items']),
                'from_minor' => $prices === [] ? null : min($prices),
                'currency' => $currency,
            ];
        }

        return $groups;
    }
}
