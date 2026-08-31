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
}
