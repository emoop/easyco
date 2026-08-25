<?php

namespace EasyCo\OperationalSales\Contracts;

use EasyCo\OperationalSales\Transaction;

/**
 * Implementations must persist a Transaction and ALL of its SaleLines
 * inside a single database transaction — mirrors
 * EasyCo\Catalog\Contracts\ProductRepository's requirement for Product +
 * Variations, for the same reason: a partially-written aggregate must
 * never be observable.
 */
interface TransactionRepository
{
    public function save(Transaction $transaction): void;

    public function findByIdWithSaleLines(string $id): ?Transaction;
}
