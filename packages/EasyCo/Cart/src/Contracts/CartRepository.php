<?php

namespace EasyCo\Cart\Contracts;

use DateTimeImmutable;
use EasyCo\Cart\Cart;

interface CartRepository
{
    /** Persists the Cart aggregate and syncs all of its CartLines. */
    public function save(Cart $cart): void;

    public function findById(string $id): ?Cart;

    public function findByAccountId(string $accountId): ?Cart;

    public function findBySessionToken(string $sessionToken): ?Cart;

    /** Used by the guest-to-account merge flow (cart-domain-design.md §8). */
    public function delete(string $cartId): void;

    /**
     * Deletes every cart whose expires_at is at or before $now. Returns
     * the number of carts deleted — used by the cart:prune Artisan
     * command (cart-domain-design.md §9). Nothing calls this
     * automatically yet.
     */
    public function deleteExpired(DateTimeImmutable $now): int;

    /**
     * Atomically claims this cart for the given orderId, iff it has not
     * already been claimed by any Order — a single conditional UPDATE
     * (WHERE order_id IS NULL), the exact same "zero-affected-rows means
     * someone else already acted" pattern EasyCo\Inventory's decrease()
     * already established (inventory-domain-design.md). Returns true if
     * THIS call performed the claim; false if the cart was already
     * claimed — by an earlier successful attempt, a concurrent one, or a
     * legitimate retry (a double-clicked "pay" button). A false result
     * is NOT an error: the caller reads findOrderIdForCart() to get the
     * existing orderId and returns that Order idempotently, per
     * checkout-domain-design.md §6.
     *
     * Deliberately a raw, model-level atomic operation — does not load
     * or touch the Cart domain entity at all, same posture
     * Inventory::decrease() takes toward StockLevel.
     */
    public function claimForOrder(string $cartId, string $orderId): bool;

    /** This cart's order_id if it has ever been claimed; null otherwise. */
    public function findOrderIdForCart(string $cartId): ?string;
}
