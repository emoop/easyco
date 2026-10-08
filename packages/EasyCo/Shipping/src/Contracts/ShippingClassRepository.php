<?php

namespace EasyCo\Shipping\Contracts;

use EasyCo\Shipping\Exceptions\ShippingClassCodeAlreadyExistsException;
use EasyCo\Shipping\ShippingClass;

interface ShippingClassRepository
{
    /** @throws ShippingClassCodeAlreadyExistsException */
    public function save(ShippingClass $class): void;

    public function findById(string $id): ?ShippingClass;

    public function findByCode(string $code): ?ShippingClass;

    /**
     * Every class, ordered by code.
     *
     * @return ShippingClass[]
     */
    public function all(): array;

    /** The store's default class (shipping stage 5e), or null when none is marked. */
    public function findDefault(): ?ShippingClass;

    /**
     * Makes $id the one default class and clears the previous one (null clears the default), atomically: one
     * transaction, the current default row locked. The database guarantees there is never more than one.
     */
    public function markDefault(?string $id): void;

    /**
     * Removes the class. A class a rate still refers to is refused by the database (the restrict foreign key) and
     * the QueryException is left to the caller; deleting an unknown id is a no-op.
     */
    public function delete(string $id): void;
}
