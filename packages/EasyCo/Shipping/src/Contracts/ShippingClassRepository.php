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

    /**
     * Removes the class. A class a rate still refers to is refused by the database (the restrict foreign key) and
     * the QueryException is left to the caller; deleting an unknown id is a no-op.
     */
    public function delete(string $id): void;
}
