<?php

namespace App\Services;

use EasyCo\Shipping\ShippingMethod;
use EasyCo\Shipping\ShippingZone;

/**
 * One BUCKET of a quote (shipping stage 5g-2b): a destination of one shape (a street address, or a pickup point) matched to its
 * zone, with that zone's active methods. The `all` quote has at most two (address, pickup); the office and the locker kinds share
 * the pickup bucket. A CARRIER method is asked ONCE per bucket: the answers are remembered here, so the office and the locker
 * read the same one (the carrier is not told the kind, design 9.3.10 Q14).
 */
final class QuoteBucket
{
    /** @var array<string, array{quote: ?\EasyCo\Shipping\Carrier\ShippingQuote, reason: ?string}> carrier method id => its answer */
    public array $carrierAnswers = [];

    /** @var array<string, ShippingMethod> */
    public readonly array $byId;

    /** @param list<ShippingMethod> $methods the zone's ACTIVE methods */
    public function __construct(
        public readonly ShippingZone $zone,
        public readonly array $methods,
    ) {
        $byId = [];

        foreach ($methods as $method) {
            $byId[(string) $method->id()] = $method;
        }

        $this->byId = $byId;
    }
}
