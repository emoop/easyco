<?php

namespace EasyCo\Shipping\Carrier;

/** What a catalogue returns for the nightly sync: the points that validated, and how many items did not. */
final class PickupPointBatch
{
    /** @param list<PickupPoint> $points */
    public function __construct(
        public readonly array $points,
        public readonly int $malformed = 0,
    ) {}
}
