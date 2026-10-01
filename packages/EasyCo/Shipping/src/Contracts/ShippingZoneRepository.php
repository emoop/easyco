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
}
