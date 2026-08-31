<?php

namespace EasyCo\Account\Contracts;

use EasyCo\Account\Account;

interface AccountRepository
{
    /**
     * Insert or update. Implementations must throw
     * EasyCo\Account\Exceptions\EmailAlreadyRegisteredException on an
     * email UNIQUE-constraint collision, never let a raw QueryException
     * escape (account-domain-design.md §5).
     */
    public function save(Account $account): void;

    public function findById(string $id): ?Account;

    /**
     * Normalizes $email to lowercase before querying — matches
     * Account's own construction-time normalization, so a lookup with
     * any casing finds the same row.
     */
    public function findByEmail(string $email): ?Account;
}
