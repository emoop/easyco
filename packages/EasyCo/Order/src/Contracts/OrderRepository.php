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
}
