<?php

namespace App\Couriers\Events;

/**
 * Fired after a points sync run that CHANGED the table (outcome ok or partial). THE HOOK POINT for the cache purge of
 * courier-integration-design.md §3.4 step 5: COUR-2a adds the public read endpoints (`pickup-points:<carrier>` cache tags) and
 * registers a listener for this event that purges those tags. Nothing listens in COUR-1a: no read endpoint, no cache to purge.
 *
 * A plain Laravel event, not a Hook: Hooks are the merchant extension mechanism (CLAUDE.md rule 10) and this has no merchant meaning.
 */
final class PickupPointsSynced
{
    public function __construct(public readonly string $carrierCode) {}
}
