<?php

namespace EasyCo\Shipping\Contracts;

use DateTimeImmutable;
use EasyCo\Shipping\Carrier\PickupPoint;

/**
 * The local copy of a courier's points (courier-integration-design.md §3.1, §3.4). Written ONLY by the sync; the checkout
 * reads it (COUR-2a). A point is never deleted: one that is no longer listed is deactivated (CLAUDE.md rule 4).
 *
 * "Seen in this run" is the run's start instant: every row a run touches is stamped with it, and deactivateUnseen() takes
 * the active rows stamped EARLIER. The instant must therefore be strictly later than the previous run's.
 */
interface PickupPointRepository
{
    /** How many points of the carrier are active right now (the circuit breaker's base). */
    public function activeCount(string $carrierCode): int;

    /**
     * Upserts a chunk of points by (carrier, reference) in ONE short transaction, stamps them with $runStartedAt,
     * reactivates a point that is listed again, and keeps its first-seen time.
     *
     * @param  list<PickupPoint>  $points
     * @return array{new: int, changed: int, unchanged: int}
     */
    public function upsertChunk(string $carrierCode, array $points, DateTimeImmutable $runStartedAt): array;

    /** Deactivates the carrier's active points not stamped by the run that started at $runStartedAt; returns how many. */
    public function deactivateUnseen(string $carrierCode, DateTimeImmutable $runStartedAt): int;
}
