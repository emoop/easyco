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
}
