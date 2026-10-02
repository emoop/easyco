<?php

namespace EasyCo\Shipping\Matching;

/**
 * THE one postcode normalization: trim, remove ALL whitespace (ASCII and every
 * Unicode separator), uppercase — "sw1a 1aa" and " SW1A1AA " both become
 * "SW1A1AA". ShippingZone applies it once when a zone is built; ZoneMatcher
 * applies it to the destination's postcode, so the two sides are normalized
 * exactly alike and then compared exactly.
 */
final class PostcodeNormalizer
{
    public static function normalize(string $postcode): string
    {
        return mb_strtoupper((string) preg_replace('/[\s\p{Z}]+/u', '', $postcode));
    }
}
