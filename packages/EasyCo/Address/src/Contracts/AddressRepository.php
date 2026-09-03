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
}
