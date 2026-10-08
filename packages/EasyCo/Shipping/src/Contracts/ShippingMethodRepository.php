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
     * Permanently removes the method; its class rates go with it (cascade). An UNKNOWN id is a NO-OP.
     * Nothing references a method by foreign key (a placed order keeps its own snapshot — name, code and
     * amount), so there is no history to protect. Added for the shipping admin (stage 5d).
     */
    public function delete(string $id): void;

    /**
     * A zone's methods, sortOrder ASC, id ASC.
     *
     * @return ShippingMethod[]
     */
    public function forZone(string $zoneId, bool $activeOnly = false): array;

    /**
     * MANY zones' methods at once, keyed by zone id (int), each list in
     * sortOrder ASC, id ASC — so an overview of every zone reads them in a
     * bounded number of queries, never one query per zone. A zone with no
     * methods simply has no key. Added for the admin overview (shipping stage
     * 5a, shipping-domain-design.md §12.3.5); it changes nothing else.
     *
     * @param  list<string|int>  $zoneIds
     * @return array<int, ShippingMethod[]>
     */
    public function forZones(array $zoneIds, bool $activeOnly = false): array;
}
