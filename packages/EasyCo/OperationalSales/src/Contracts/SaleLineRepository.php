<?php

namespace EasyCo\OperationalSales\Contracts;

/**
 * A minimal, deliberately narrow read contract — one method, not a general
 * SaleLine repository. SaleLines are otherwise reachable only through
 * TransactionRepository::findByIdWithSaleLines() (the aggregate boundary);
 * this exists purely to answer R7 (order-lifecycle-design.md §2.2) without
 * loading every SaleLine of every Transaction that has ever touched a
 * given originating line. Do not grow this into a general SaleLine
 * repository — a new query need is a new, equally narrow method, never a
 * reason to add findById()/findByTransactionId()/etc. here.
 */
interface SaleLineRepository
{
    /**
     * Sums quantity_returned across every REFUND SaleLine sharing
     * $originatingSaleLineId — R7's own "already returned" read
     * (order-lifecycle-design.md §2.2), a query, never a stored running
     * counter (operational-sales-domain-design.md §3.4 revised). Zero
     * when the line has never been refunded at all.
     */
    public function sumQuantityReturnedForOriginatingLine(string $originatingSaleLineId): int;
}
