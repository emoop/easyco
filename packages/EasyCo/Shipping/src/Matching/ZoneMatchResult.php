<?php

namespace EasyCo\Shipping\Matching;

use EasyCo\Shipping\ShippingZone;
use LogicException;

/**
 * The outcome of ZoneMatcher::match(): either the zone that matched, or an
 * explicit refusal with a reason code. Never null and never an empty list, so a
 * caller cannot forget the "cannot be shipped" case (shipping-domain-design.md
 * §4: an address matching no zone is refused, not offered a free delivery).
 */
final class ZoneMatchResult
{
    public const NO_ZONE_FOR_DESTINATION = 'no_zone_for_destination';

    private function __construct(
        private readonly ?ShippingZone $zone,
        private readonly ?string $refusalReason,
    ) {
    }

    public static function matched(ShippingZone $zone): self
    {
        return new self($zone, null);
    }

    public static function refused(string $reason): self
    {
        return new self(null, $reason);
    }

    public function isMatched(): bool
    {
        return $this->zone !== null;
    }

    /** @throws LogicException when the destination was refused — check isMatched() first */
    public function zone(): ShippingZone
    {
        return $this->zone ?? throw new LogicException("No zone matched ({$this->refusalReason}); check isMatched() before zone().");
    }

    /** Null when a zone matched. */
    public function refusalReason(): ?string
    {
        return $this->refusalReason;
    }
}
