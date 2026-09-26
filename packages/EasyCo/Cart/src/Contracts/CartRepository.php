<?php

namespace EasyCo\Cart\Contracts;

use DateTimeImmutable;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartClaim;

interface CartRepository
{
    /** Persists the Cart aggregate and syncs all of its CartLines. */
    public function save(Cart $cart): void;

    /**
     * A **live** cart only — deliberately null for a cart that has been claimed, so
     * no code path can reconstitute a `Cart` aggregate from a row whose live
     * identity is NULL (cart-domain-design.md §14.1; `Cart`'s own XOR invariant
     * would reject it anyway, as a 500). The replay check reads
     * findClaimForIdentity() instead — never a `Cart`.
     */
    public function findById(string $id): ?Cart;

    /** The identity's CURRENT cart. A claimed cart is invisible here by construction (§14.1). */
    public function findByAccountId(string $accountId): ?Cart;

    /** The identity's CURRENT cart. A claimed cart is invisible here by construction (§14.1). */
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
     *
     * SINCE cart-domain-design.md §14.1 IT ALSO MOVES THE CART'S IDENTITY: the same
     * single statement copies account_id/session_token into
     * claimed_account_id/claimed_session_token and NULLs the live columns, so the
     * claimed row stops being the identity's current cart (and the unique indexes on
     * the live columns are free for the next purchase). One statement, so the claim
     * stays exactly as atomic as it was, and the caller's zero-affected-rows meaning
     * is unchanged.
     */
    public function claimForOrder(string $cartId, string $orderId): bool;

    /**
     * This cart's order_id if it has ever been claimed; null otherwise.
     *
     * CLAIM-ID-BLIND, DELIBERATELY, AND WITH A WARNING: it answers for ANY cart id,
     * so it must never be used to answer a request — that is findClaimForIdentity()'s
     * job, and only that one cannot be called without stating who is asking
     * (cart-domain-design.md §14.2). Legitimate uses: diagnostics, and tests
     * asserting the claim itself.
     */
    public function findOrderIdForCart(string $cartId): ?string;

    /**
     * The claim on $cartId — but ONLY when the requester is the identity recorded at
     * claim time (the account, or the session's own token).
     *
     * Null for a foreign cart, for an unknown id, and for a cart that is not claimed
     * at all, and deliberately NOT distinguishable from one another: a caller can
     * therefore never learn that someone else's cart exists, let alone which order it
     * produced (§14.2's own truth table). This is the only claim accessor a request
     * path may use.
     */
    public function findClaimForIdentity(string $cartId, ?string $accountId, ?string $sessionToken): ?CartClaim;
}
