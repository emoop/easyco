<?php

namespace EasyCo\OperationalSales\Contracts;

use EasyCo\OperationalSales\Client;

interface ClientRepository
{
    public function save(Client $client): void;

    public function findById(string $id): ?Client;
}
