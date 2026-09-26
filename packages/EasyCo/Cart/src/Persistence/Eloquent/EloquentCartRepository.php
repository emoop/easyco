<?php

namespace EasyCo\Cart\Persistence\Eloquent;

use DateTimeImmutable;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartClaim;
use EasyCo\Cart\CartLine;
use EasyCo\Cart\Contracts\CartRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Maps the Cart aggregate (and all of its CartLines) onto
 * carts / cart_lines.
 *
 * save() syncs the whole line set every call: upserts every line
 * currently on the aggregate, then deletes any cart_lines row for
 * this cart that isn't among them (covers Cart::removeLine(), which
 * only mutates the in-memory aggregate — the actual row deletion
 * happens here, at save() time).
 */
final class EloquentCartRepository implements CartRepository
{
    public function save(Cart $cart): void
    {
        DB::transaction(function () use ($cart): void {
            $cartModel = $cart->id() !== null
                ? CartModel::findOrFail($cart->id())
                : new CartModel();

            $cartModel->account_id = $cart->accountId();
            $cartModel->session_token = $cart->sessionToken();
            $cartModel->expires_at = $cart->expiresAt();
            $cartModel->applied_promotion_code = $cart->appliedPromotionCode();
            $cartModel->save();

            if ($cart->id() === null) {
                $cart->assignId((string) $cartModel->id);
            }

            $keepLineIds = [];

            foreach ($cart->lines() as $line) {
                $keepLineIds[] = $this->saveLine($cartModel, $line);
            }

            CartLineModel::where('cart_id', $cartModel->id)
                ->whereNotIn('id', $keepLineIds)
                ->delete();
        });
    }

    /**
     * Returns the persisted row's id (also assigned onto $line if it
     * didn't have one yet).
     *
     * A genuinely concurrent double-add (two requests both loading a
     * cart without this variation yet, both inserting a brand-new
     * line for it) can still hit cart_lines_cart_variation_unique even
     * though Cart::addLine() already prevents a duplicate line within
     * one in-memory aggregate — that in-memory check can't see another
     * request's uncommitted insert. Rather than let the raw
     * QueryException leak (rule 3) or surface a confusing "duplicate"
     * error for someone who just clicked "add to cart" once, the
     * collision is treated as "the other request already created this
     * row" and self-heals: the existing row's quantity is incremented
     * by this line's quantity, mirroring exactly what addLine() would
     * have done had it seen the other line first.
     */
    private function saveLine(CartModel $cartModel, CartLine $line): int
    {
        if ($line->id() !== null) {
            $model = CartLineModel::findOrFail($line->id());
            $model->quantity = $line->quantity();
            $model->price_at_add_minor = $line->priceAtAddMinor();
            $model->price_at_add_currency = $line->priceAtAddCurrency();
            $model->save();

            return $model->id;
        }

        $model = new CartLineModel([
            'cart_id' => $cartModel->id,
            'variation_id' => $line->variationId(),
            'quantity' => $line->quantity(),
            'price_at_add_minor' => $line->priceAtAddMinor(),
            'price_at_add_currency' => $line->priceAtAddCurrency(),
        ]);

        try {
            $model->save();
        } catch (QueryException $e) {
            if (! $this->isCartVariationUniqueViolation($e)) {
                throw $e;
            }

            $existing = CartLineModel::where('cart_id', $cartModel->id)
                ->where('variation_id', $line->variationId())
                ->firstOrFail();
            $existing->increment('quantity', $line->quantity());

            $line->assignId((string) $existing->id);

            return $existing->id;
        }

        $line->assignId((string) $model->id);

        return $model->id;
    }

    /**
     * A LIVE, identity-carrying cart only. Two rows are deliberately invisible here:
     *
     * - a claimed one (`order_id` set) — its live identity is NULL by construction
     *   (cart-domain-design.md §14.1), and `Cart`'s own XOR invariant would reject it
     *   as a 500 rather than a domain answer;
     * - an orphaned one — `carts.order_id` is `nullOnDelete`, so deleting an Order
     *   un-claims the row without restoring the identity it moved away. Such a row is
     *   disposable history, not a cart anybody can add to.
     *
     * Both exclusions live HERE, so "no `Cart` aggregate is ever built from a row
     * without a live identity" is a property of this repository rather than a rule
     * every caller must remember.
     */
    public function findById(string $id): ?Cart
    {
        $model = CartModel::with('lines')
            ->whereNull('order_id')
            ->where(function ($query): void {
                $query->whereNotNull('account_id')->orWhereNotNull('session_token');
            })
            ->find($id);

        return $model !== null ? $this->toDomainCart($model) : null;
    }

    public function findByAccountId(string $accountId): ?Cart
    {
        $model = CartModel::with('lines')->where('account_id', $accountId)->first();

        return $model !== null ? $this->toDomainCart($model) : null;
    }

    public function findBySessionToken(string $sessionToken): ?Cart
    {
        $model = CartModel::with('lines')->where('session_token', $sessionToken)->first();

        return $model !== null ? $this->toDomainCart($model) : null;
    }

    public function delete(string $cartId): void
    {
        CartModel::where('id', $cartId)->delete();
    }

    public function deleteExpired(DateTimeImmutable $now): int
    {
        return CartModel::where('expires_at', '<=', $now)->delete();
    }

    /**
     * One statement, so the claim stays exactly as atomic as it has always been:
     * `order_id` is set, and in the same UPDATE the cart's identity MOVES to the
     * `claimed_*` columns while the live ones are NULLed — cart-domain-design.md
     * §14.1. Zero-affected-rows therefore still means precisely "some other attempt
     * claimed this cart first".
     *
     * `DB::raw()` on the right-hand sides is what makes the move a single statement:
     * the values are copied server-side, never read into PHP first (which would open
     * exactly the race this UPDATE exists to close).
     *
     * THE ORDER OF THE ASSIGNMENTS IS LOAD-BEARING — the `claimed_*` copies MUST be
     * listed before the two nulls, never after. MySQL (this project's engine, tests
     * included) evaluates a multi-column SET left to right, and a later expression
     * sees the value an earlier assignment in that SAME statement just wrote. So
     * `'claimed_session_token' => DB::raw('session_token')` placed AFTER
     * `'session_token' => null` would copy NULL, and the identity the claim exists to
     * hand over would be gone. Note the failure mode: silent. The UPDATE still
     * affects its one row, `order_id` is still set, and the cart simply stops
     * answering findClaimForIdentity() afterwards.
     */
    public function claimForOrder(string $cartId, string $orderId): bool
    {
        $affected = CartModel::where('id', $cartId)
            ->whereNull('order_id')
            ->update([
                'order_id' => $orderId,
                // MUST come before the two nulls below: MySQL evaluates this SET list
                // left to right, so a DB::raw() reference placed after its own column
                // was NULLed would copy the NULL. See the docblock's ordering note.
                'claimed_account_id' => DB::raw('account_id'),
                'claimed_session_token' => DB::raw('session_token'),
                'account_id' => null,
                'session_token' => null,
            ]);

        return $affected > 0;
    }

    /**
     * CLAIM-ID-BLIND by contract — see the interface's own warning: for tests and
     * diagnostics, never for answering a request. findClaimForIdentity() below is the
     * identity-checked accessor every request path must use.
     */
    public function findOrderIdForCart(string $cartId): ?string
    {
        $orderId = CartModel::where('id', $cartId)->value('order_id');

        return $orderId !== null ? (string) $orderId : null;
    }

    public function findClaimForIdentity(string $cartId, ?string $accountId, ?string $sessionToken): ?CartClaim
    {
        if ($accountId === null && $sessionToken === null) {
            // Nothing to match on: an identity-less caller can never own a claim.
            // Matching anyway would be exactly the leak this method exists to prevent.
            return null;
        }

        $row = CartModel::query()
            ->where('id', $cartId)
            ->whereNotNull('order_id')
            ->where(function ($query) use ($accountId, $sessionToken): void {
                if ($accountId !== null) {
                    $query->orWhere('claimed_account_id', $accountId);
                }

                if ($sessionToken !== null) {
                    $query->orWhere('claimed_session_token', $sessionToken);
                }
            })
            ->first(['id', 'order_id']);

        return $row !== null
            ? new CartClaim(cartId: (string) $row->id, orderId: (string) $row->order_id)
            : null;
    }

    /**
     * Detects a violation of cart_lines_cart_variation_unique,
     * confirmed via a real SHOW CREATE TABLE against the dev database
     * rather than assumed. SQLSTATE 23000 + driver error code (MySQL
     * 1062 / SQLite 19) is the primary check, then errorInfo[2]
     * narrows to this specific constraint — never $e->getMessage()
     * string matching (CLAUDE.md rule 3).
     */
    private function isCartVariationUniqueViolation(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];
        $sqlState = $errorInfo[0] ?? null;
        $driverErrorCode = (int) ($errorInfo[1] ?? 0);

        if ($sqlState !== '23000' || ! in_array($driverErrorCode, [1062, 19], true)) {
            return false;
        }

        $driverErrorMessage = (string) ($errorInfo[2] ?? '');

        return str_contains($driverErrorMessage, 'cart_lines_cart_variation_unique')
            || str_contains($driverErrorMessage, 'cart_lines.variation_id');
    }

    private function toDomainCart(CartModel $model): Cart
    {
        $lines = $model->lines
            ->map(fn (CartLineModel $lineModel) => $this->toDomainCartLine($lineModel))
            ->all();

        return Cart::reconstituteFromStorage(
            id: (string) $model->id,
            accountId: $model->account_id !== null ? (string) $model->account_id : null,
            sessionToken: $model->session_token,
            expiresAt: $model->expires_at->toDateTimeImmutable(),
            lines: $lines,
            appliedPromotionCode: $model->applied_promotion_code,
        );
    }

    private function toDomainCartLine(CartLineModel $model): CartLine
    {
        return new CartLine(
            id: (string) $model->id,
            cartId: (string) $model->cart_id,
            variationId: (string) $model->variation_id,
            quantity: $model->quantity,
            priceAtAddMinor: $model->price_at_add_minor,
            priceAtAddCurrency: $model->price_at_add_currency,
        );
    }
}
