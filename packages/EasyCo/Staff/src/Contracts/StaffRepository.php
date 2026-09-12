<?php

namespace EasyCo\Staff\Contracts;

use EasyCo\Staff\Exceptions\StaffEmailAlreadyRegisteredException;
use EasyCo\Staff\Staff;

interface StaffRepository
{
    /**
     * @throws StaffEmailAlreadyRegisteredException On a UNIQUE(email)
     *   collision — never let a raw QueryException escape.
     */
    public function save(Staff $staff): void;

    public function findById(string $id): ?Staff;

    /** Normalizes $email to lowercase before querying. */
    public function findByEmail(string $email): ?Staff;

    /**
     * True if at least one Staff row exists — including soft-deleted
     * ones (query withTrashed()). Used only by the
     * staff:create-administrator bootstrap command's chicken-and-egg
     * guard (staff-access-domain-design.md §8): if the system has ever
     * had a Staff member, it has already been bootstrapped once. (This
     * means a soft-deleted sole administrator will require --force to
     * bootstrap a replacement — a deliberate, recoverable trade-off,
     * not a bug.)
     */
    public function any(): bool;
}
