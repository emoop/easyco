<?php

namespace EasyCo\Cart\Persistence\Eloquent;

use DateTimeImmutable;
use EasyCo\Cart\Cart;
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

    public function findById(string $id): ?Cart
    {
        $model = CartModel::with('lines')->find($id);

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

    public function claimForOrder(string $cartId, string $orderId): bool
    {
        $affected = CartModel::where('id', $cartId)
            ->whereNull('order_id')
            ->update(['order_id' => $orderId]);

        return $affected > 0;
    }

    public function findOrderIdForCart(string $cartId): ?string
    {
        $orderId = CartModel::where('id', $cartId)->value('order_id');

        return $orderId !== null ? (string) $orderId : null;
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
