<?php

namespace EasyCo\Payment;

use EasyCo\Pricing\Money;
use InvalidArgumentException;

/**
 * The goods amount a refund pays back for ONE original sale line — the row the
 * per-line cap sums, so that cancelling a refund frees exactly its own room
 * (shipping-domain-design.md §7.2.2). saleLineId is a plain string, never
 * validated against a real line here (cross-domain by id).
 *
 * Zero is valid: the goods came back and no money is paid for that line.
 */
final class RefundLine
{
    public function __construct(
        public readonly string $saleLineId,
        public readonly Money $amount,
    ) {
        if (trim($saleLineId) === '') {
            throw new InvalidArgumentException('RefundLine saleLineId must not be empty.');
        }

        if ($amount->isNegative()) {
            throw new InvalidArgumentException('RefundLine amount must not be negative.');
        }
    }
}
