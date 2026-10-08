<?php

namespace EasyCo\Shipping\Contracts;

use EasyCo\Shipping\ShippingZone;

interface ShippingZoneRepository
{
    public function save(ShippingZone $zone): void;

    public function findById(string $id): ?ShippingZone;

    /**
     * Every zone, in the match order: sortOrder ASC, id ASC.
     *
     * @return ShippingZone[]
     */
    public function allOrdered(): array;

    /**
     * Permanently removes the zone. An UNKNOWN id is a NO-OP (idempotent, like a delete that already
     * happened) — deciding whether that is an error is the caller's. A zone that still has methods is
     * refused by the database itself: the foreign key `ship_methods_zone_id_foreign` is `restrict`, and the
     * QueryException is left to propagate (the app-layer writer checks first and translates it; this is
     * only the backstop). Zone ids are never reused, so no history points at a removed row.
     */
    public function delete(string $id): void;
}
