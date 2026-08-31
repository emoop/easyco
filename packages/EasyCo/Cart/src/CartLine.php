<?php

namespace EasyCo\Cart;

use InvalidArgumentException;
use LogicException;

/**
 * One line of a Cart — a Variation plus how many units are wanted.
 *
 * MUTABLE, UNLIKE SaleLine — this is the single biggest contrast with
 * OperationalSales\SaleLine's hard immutability rule
 * (operational-sales-domain-design.md §3.2), and deliberately so:
 * SaleLine is a permanent historical record of what already happened;
 * CartLine is live, working state that hasn't happened yet and is
 * expected to change constantly (quantity edits, removals) right up
 * until Checkout turns it into something else entirely. Nothing here
 * should be read as inconsistent with SaleLine's rule — they represent
 * fundamentally different kinds of data.
 *
 * priceAtAddMinor/priceAtAddCurrency are DISPLAY-ONLY — see
 * cart-domain-design.md §5. NOTHING may ever compute a total from
 * these fields; that's why the accessor is named priceAtAdd(), never
 * price()/unitPrice(). The authoritative price is always resolved live
 * via PriceResolver — see Cart's own docblock and §4 of the design doc.
 */
final class CartLine
{
    /**
     * @param string $cartId The owning Cart's id, or the empty-string
     *   placeholder — same sentinel convention as
     *   OperationalSales\SaleLine's transactionId — meaning this line
     *   has not yet been attached to a persisted Cart. See
     *   assignCartId() below for how the placeholder is resolved.
     */
    public function __construct(
        private ?string $id,
        private string $cartId,
        private readonly string $variationId,
        private int $quantity,
        private readonly ?int $priceAtAddMinor = null,
        private readonly ?string $priceAtAddCurrency = null,
    ) {
        if ($variationId === '') {
            throw new InvalidArgumentException('CartLine variationId must not be empty.');
        }

        self::assertValidQuantity($quantity);
        self::assertPriceAtAddBothOrNeither($priceAtAddMinor, $priceAtAddCurrency);
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('CartLine already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    /**
     * Back-fills cartId once the owning Cart aggregate itself has been
     * persisted and assigned one. Only meaningful for a CartLine
     * created before its parent Cart had an id — mirrors
     * OperationalSales\SaleLine::assignTransactionId() exactly, same
     * empty-string sentinel, same one-time-only guard.
     */
    public function assignCartId(string $cartId): void
    {
        if ($this->cartId !== '') {
            throw new LogicException('CartLine already has a cartId; assignCartId() is a one-time operation.');
        }

        $this->cartId = $cartId;
    }

    public function cartId(): string
    {
        return $this->cartId;
    }

    public function variationId(): string
    {
        return $this->variationId;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function priceAtAddMinor(): ?int
    {
        return $this->priceAtAddMinor;
    }

    public function priceAtAddCurrency(): ?string
    {
        return $this->priceAtAddCurrency;
    }

    public function setQuantity(int $quantity): void
    {
        self::assertValidQuantity($quantity);
        $this->quantity = $quantity;
    }

    public function increaseQuantity(int $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException("CartLine increaseQuantity amount must be positive, got {$amount}.");
        }

        $this->quantity += $amount;
    }

    private static function assertValidQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException("CartLine quantity must be at least 1, got {$quantity}.");
        }
    }

    private static function assertPriceAtAddBothOrNeither(?int $minor, ?string $currency): void
    {
        if (($minor === null) !== ($currency === null)) {
            throw new InvalidArgumentException(
                'CartLine priceAtAddMinor and priceAtAddCurrency must be both null or both set.'
            );
        }
    }
}
