<?php

namespace EasyCo\Shipping\Matching;

use EasyCo\Shipping\Contracts\SettlementNameNormalizer;
use EasyCo\Shipping\ShippingZone;

/**
 * Finds the ONE zone a destination falls in — shipping-domain-design.md §4.
 * Pure: it reads the zones it is given and the normalizer it is given, nothing
 * else (no framework, no repository, no Hook, no Address/Order/Cart).
 *
 * Zones are checked in `sortOrder` ascending, then `id` ascending (sortOrder is
 * not unique), and THE FIRST MATCH WINS: an order matches exactly one zone.
 *
 * A zone matches when its countryCodes contain the destination's country AND
 * at least one of:
 *  - the zone has no settlement names and no postcodes (it is not narrowed);
 *  - the destination's settlement, normalized, EQUALS one of the zone's names,
 *    normalized;
 *  - the destination's postcode, normalized exactly like the zone's (trim, all
 *    whitespace removed, uppercase), EQUALS one of the zone's postcodes.
 * Equality after normalization — never a substring or a prefix. A blank
 * settlement or postcode matches nothing. A pickup point has no postcode, so
 * its postcode is ignored and a postcodes-only zone cannot match it.
 *
 * No match is an explicit refusal (no_zone_for_destination).
 */
final class ZoneMatcher
{
    /** @param iterable<ShippingZone> $zones in any order; sorted here */
    public function match(iterable $zones, ZoneDestination $destination, SettlementNameNormalizer $normalizer): ZoneMatchResult
    {
        $ordered = is_array($zones) ? $zones : iterator_to_array($zones, false);
        usort($ordered, self::byPrecedence(...));

        $settlement = $destination->settlement === null ? '' : $normalizer->normalize($destination->settlement);
        $postcode = $destination->isPickupPoint || $destination->postcode === null
            ? ''
            : PostcodeNormalizer::normalize($destination->postcode);

        foreach ($ordered as $zone) {
            if ($this->matches($zone, $destination->countryCode, $settlement, $postcode, $normalizer)) {
                return ZoneMatchResult::matched($zone);
            }
        }

        return ZoneMatchResult::refused(ZoneMatchResult::NO_ZONE_FOR_DESTINATION);
    }

    private function matches(ShippingZone $zone, string $country, string $settlement, string $postcode, SettlementNameNormalizer $normalizer): bool
    {
        if (! in_array($country, $zone->countryCodes(), true)) {
            return false;
        }

        $names = $zone->settlementNames();
        $postcodes = $zone->postcodes();

        if ($names === null && $postcodes === null) {
            return true;
        }

        if ($settlement !== '' && $names !== null) {
            foreach ($names as $name) {
                if ($normalizer->normalize($name) === $settlement) {
                    return true;
                }
            }
        }

        // The zone's postcodes were normalized when it was built (ShippingZone).
        return $postcode !== '' && $postcodes !== null && in_array($postcode, $postcodes, true);
    }

    /** sortOrder ascending, then id ascending (numerically when both are numbers; an unsaved zone sorts last). */
    private static function byPrecedence(ShippingZone $a, ShippingZone $b): int
    {
        if ($a->sortOrder() !== $b->sortOrder()) {
            return $a->sortOrder() <=> $b->sortOrder();
        }

        $idA = $a->id();
        $idB = $b->id();

        if ($idA === $idB) {
            return 0;
        }

        if ($idA === null || $idB === null) {
            return $idA === null ? 1 : -1;
        }

        return ctype_digit($idA) && ctype_digit($idB) ? (int) $idA <=> (int) $idB : strcmp($idA, $idB);
    }
}
