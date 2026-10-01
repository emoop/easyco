<?php

namespace EasyCo\Shipping\Contracts;

use EasyCo\Shipping\Exceptions\UnknownShippingClassException;
use EasyCo\Shipping\ShippingMethod;

interface ShippingMethodRepository
{
    /**
     * Saves the method row and replaces its class rates in ONE database
     * transaction, with no external call inside it.
     *
     * @throws UnknownShippingClassException A class rate names a class code that does not exist.
     */
    public function save(ShippingMethod $method): void;

    public function findById(string $id): ?ShippingMethod;

    /**
     * A zone's methods, sortOrder ASC, id ASC.
     *
     * @return ShippingMethod[]
     */
    public function forZone(string $zoneId, bool $activeOnly = false): array;
}
