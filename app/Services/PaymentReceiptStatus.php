<?php

namespace App\Services;

use EasyCo\Pricing\Money;

/**
 * Where one bank-transfer payment stands against the receipts recorded for it — the ONE source of
 * the figures and the arithmetic (shipping-domain-design.md §7.2.20 §3, §7): the service decides
 * with it, the (later) dialog hint shows it, and neither adds a number itself. state() is a
 * PaymentReceiptState.
 */
final class PaymentReceiptStatus
{
    public function __construct(
        private readonly Money $expected,
        private readonly Money $received,
        private readonly int $effectiveCount,
        private readonly bool $settled,
    ) {
    }

    /** payments.amount_minor: what the order's price said the transfer should be. */
    public function expected(): Money
    {
        return $this->expected;
    }

    /** The sum of the effective receipts. */
    public function received(): Money
    {
        return $this->received;
    }

    /** received − expected: negative = short, positive = over, zero = exact. */
    public function difference(): Money
    {
        return $this->received->subtract($this->expected);
    }

    public function effectiveCount(): int
    {
        return $this->effectiveCount;
    }

    /** At least one receipt, and they add up to exactly the expected amount. */
    public function matches(): bool
    {
        return $this->effectiveCount > 0 && $this->received->equals($this->expected);
    }

    public function state(): PaymentReceiptState
    {
        if ($this->settled) {
            return PaymentReceiptState::SETTLED;
        }

        if ($this->effectiveCount === 0) {
            return PaymentReceiptState::NONE;
        }

        return $this->received->subtract($this->expected)->isNegative()
            ? PaymentReceiptState::PARTIAL
            : PaymentReceiptState::MISMATCH;
    }

    /** At least one receipt sits on a payment that is not settled: the money is in hand, not reconciled. */
    public function isUnreconciled(): bool
    {
        return in_array($this->state(), [PaymentReceiptState::PARTIAL, PaymentReceiptState::MISMATCH], true);
    }

    /** What this payment would look like if an effective receipt of $old were replaced by one of $new (a correction before settlement). */
    public function withReceiptReplaced(Money $old, Money $new): self
    {
        return new self($this->expected, $this->received->subtract($old)->add($new), $this->effectiveCount, false);
    }

    /** What this payment would look like with one more receipt of $amount (the live hint, the exact-match test). */
    public function withReceipt(Money $amount): self
    {
        return new self($this->expected, $this->received->add($amount), $this->effectiveCount + 1, false);
    }
}
