<?php

namespace EasyCo\Address\Contracts;

use EasyCo\Address\Address;

interface AddressRepository
{
    public function save(Address $address): void;

    public function findById(string $id): ?Address;

    /**
     * Every saved address for the given account — a guest/one-off
     * address (accountId: null) is never returned by this method, even
     * if it otherwise matches, since it was never tied to an account.
     * See address-domain-design.md §6.
     *
     * @return Address[]
     */
    public function findByAccountId(string $accountId): array;

    /**
     * ACC-2b. The id of the account's default address, or null when it has none.
     */
    public function defaultAddressId(string $accountId): ?string;

    /**
     * ACC-2b. Hard-deletes ONE saved address of this account in a transaction; false (nothing touched) when the id is unknown or belongs
     * to another account. When it was the default, the most recently UPDATED remaining address (the higher id on a tie) becomes the
     * default; none left means no default. Orders keep their own snapshot (orders.address_id goes NULL by the foreign key).
     */
    public function deleteOwned(string $id, string $accountId): bool;

    /**
     * ACC-2b. Makes this address the account's default in ONE transaction (the old default is cleared first, then this one set);
     * idempotent; false when the id is unknown or belongs to another account. Never touches updated_at.
     */
    public function setDefault(string $id, string $accountId): bool;

    /**
     * ACC-2b. Bumps updated_at of this account's address (a checkout that reused it). False when it is not this account's.
     */
    public function touchOwned(string $id, string $accountId): bool;
}
