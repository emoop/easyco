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
 *
 * `destinations` (stage 5g-2b): the kinds of delivery the group offers, ordered address, office, locker — the union of its methods'
 * SERVED kinds in a single-kind quote, and of the kinds each method really has a quote for in an `all` quote, where `from_minor` is
 * the lowest available price over those kinds (indicative in a preview). One builder for both.
 */
final class QuoteGroups
{
    /**
     * @param  list<MethodQuote>  $methods
     * @param  array<string, array<string, MethodQuote>>|null  $kindQuotes  `all` quote only: method id => kind => that kind's quote
     * @return list<array{courier: ?string, methods: list<string>, from_minor: ?int, currency: string, destinations: list<string>}>
     */
    public static function build(array $methods, string $currency, ?array $kindQuotes = null): array
    {
        $groups = [];

        foreach (ShippingCourier::group($methods, static fn (MethodQuote $method): ?string => $method->courier) as $group) {
            $prices = [];
            $kinds = [];

            foreach ($group['items'] as $method) {
                foreach ($kindQuotes === null ? [$method] : array_values($kindQuotes[$method->methodId] ?? []) as $quote) {
                    if ($quote->amountMinor !== null) {
                        $prices[] = $quote->amountMinor;
                    }
                }

                foreach ($kindQuotes === null ? ($method->servedKinds ?? []) : array_keys($kindQuotes[$method->methodId] ?? []) as $kind) {
                    $kinds[$kind] = true;
                }
            }

            $groups[] = [
                'courier' => $group['courier'],
                'methods' => array_map(static fn (MethodQuote $method): string => $method->methodId, $group['items']),
                'from_minor' => $prices === [] ? null : min($prices),
                'currency' => $currency,
                'destinations' => array_values(array_filter(['address', 'office', 'locker'], static fn (string $kind): bool => isset($kinds[$kind]))),
            ];
        }

        return $groups;
    }
}
