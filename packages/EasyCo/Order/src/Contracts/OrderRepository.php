<?php

namespace EasyCo\Order\Contracts;

use EasyCo\Order\Order;

/**
 * Deliberately minimal — no findByAccountId()/findByClientId() yet. Per
 * checkout-domain-design.md §6, Checkout finds "does this cart already
 * have an order" by reading carts.order_id and calling findById() with
 * it; a "list my orders" finder is future HTTP-layer work, not this one.
 */
interface OrderRepository
{
    public function save(Order $order): void;

    public function findById(string $id): ?Order;

    /**
     * Answers "has this account ever placed an order" for
     * new_customers_only. A guest order (account_id null) is invisible
     * to this check — a customer who ordered as a guest and later
     * registered counts as new, consistent with §8.1's own deliberate
     * no-guest-deduplication decision. An order in any status counts,
     * including CANCELLED: they did place one.
     */
    public function hasAnyForAccount(string $accountId): bool;

    /**
     * The locked sibling of findById(): the same order, the same null when
     * no row has that id, read under a row lock.
     *
     * IT TAKES A ROW LOCK, WHICH IS ONLY MEANINGFUL INSIDE AN OPEN
     * TRANSACTION: outside one the lock is released as the statement
     * finishes, so the read is an ordinary snapshot read and this method
     * buys nothing. It exists for read-then-write paths — a caller that
     * decides *whether* to write from the status it has just read must hold
     * that decision's own row until its write lands, or another writer may
     * have moved the status in between. save() rewrites every column
     * unconditionally, so it is this read, not the write, that makes such a
     * path safe.
     */
    public function findByIdForUpdate(string $id): ?Order;
}
