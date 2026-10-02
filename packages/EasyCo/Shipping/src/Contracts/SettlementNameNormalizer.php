<?php

namespace EasyCo\Shipping\Contracts;

/**
 * Puts a settlement name into the form in which two spellings of the same
 * place compare EQUAL (shipping-domain-design.md §4, settlement matching).
 *
 * Used by the ZoneMatcher on BOTH sides of the comparison — each zone's stored
 * name and the destination's settlement — and nowhere else: names are stored
 * as the merchant entered them, normalization is applied only when matching.
 * The result is for comparison only; it is never shown or stored.
 *
 * Which implementation applies is chosen by the store's locale in the app
 * layer; an extension package adds a locale by binding its own implementation
 * under `shipping.settlement_normalizer.<locale>` (see the design doc).
 *
 * An implementation must be pure and total: the same input always gives the
 * same output, and malformed input (invalid UTF-8) gives an empty string — a
 * name that matches nothing — never an exception, because the destination is
 * customer-supplied.
 */
interface SettlementNameNormalizer
{
    public function normalize(string $name): string;
}
