<?php

namespace App\Services;

use EasyCo\Pricing\Money;
use RuntimeException;

/**
 * A NAMED refusal of a shipping choice (stage 4d). The reason is the contract; the message is developer text only and
 * never contains anything the customer typed. price_changed alone carries data: the NEW figure, recomputed by the server.
 */
final class ShippingRefusal extends RuntimeException
{
    private function __construct(
        public readonly ShippingRefusalReason $reason,
        string $message,
        private readonly ?Money $newAmount = null,
    ) {
        parent::__construct($message);
    }

    public static function because(ShippingRefusalReason $reason, string $message): self
    {
        return new self($reason, $message);
    }

    public static function priceChanged(Money $newAmount): self
    {
        return new self(ShippingRefusalReason::PRICE_CHANGED, 'The shipping price is not the one that was shown.', $newAmount);
    }

    /** The recomputed price; non-null only for price_changed. */
    public function newAmount(): ?Money
    {
        return $this->newAmount;
    }
}
