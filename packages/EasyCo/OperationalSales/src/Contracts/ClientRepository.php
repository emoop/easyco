<?php

namespace EasyCo\OperationalSales\Contracts;

use EasyCo\OperationalSales\Client;

interface ClientRepository
{
    public function save(Client $client): void;

    public function findById(string $id): ?Client;

    /**
     * One Account maps to at most one Client (a real, DB-enforced
     * unique constraint — see the account_id migration) — this is what
     * lets checkout-domain-design.md §8.1's "find-or-create on first
     * checkout" logic be a well-formed question.
     */
    public function findByAccountId(string $accountId): ?Client;
}
