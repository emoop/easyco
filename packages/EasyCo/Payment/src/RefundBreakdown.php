<?php

namespace EasyCo\Payment;

use EasyCo\Pricing\Money;
use InvalidArgumentException;

/**
 * What a refund is made of — shipping-domain-design.md §7.2.2:
 * total = goods + shipping + adjustment − deduction.
 *
 *  - goods: the money paid back for returned goods, per line in $lines;
 *  - shipping: a shipping refund;
 *  - adjustment: a money-only correction or goodwill amount (R3);
 *  - deduction: retained revenue, never a goods figure; it REQUIRES a free-text
 *    reason. It is a refund-level figure and appears on no sale line.
 *
 * Every part is non-negative and in one currency. When $lines is given they
 * must add up to the goods component exactly. A breakdown with no lines is
 * legal (a legacy row, a money-only refund).
 */
final class RefundBreakdown
{
    /** @param list<RefundLine> $lines */
    public function __construct(
        public readonly Money $goods,
        public readonly Money $shipping,
        public readonly Money $adjustment,
        public readonly Money $deduction,
        public readonly ?string $deductionReason = null,
        public readonly array $lines = [],
    ) {
        $currency = $goods->currency();

        foreach (['goods' => $goods, 'shipping' => $shipping, 'adjustment' => $adjustment, 'deduction' => $deduction] as $name => $part) {
            if (! $part->currency()->equals($currency)) {
                throw new InvalidArgumentException("RefundBreakdown {$name} is in a different currency than goods.");
            }

            if ($part->isNegative()) {
                throw new InvalidArgumentException("RefundBreakdown {$name} must not be negative.");
            }
        }

        if ($deduction->isPositive() && trim((string) $deductionReason) === '') {
            throw new InvalidArgumentException('RefundBreakdown: a deduction requires a reason.');
        }

        if ($this->total()->isNegative()) {
            throw new InvalidArgumentException('RefundBreakdown: the deduction exceeds goods + shipping + adjustment; the refund total would be negative.');
        }

        if ($lines !== []) {
            $sum = Money::zero($currency);
            $seen = [];

            foreach ($lines as $line) {
                if (! $line instanceof RefundLine) {
                    throw new InvalidArgumentException('RefundBreakdown lines must all be RefundLine instances.');
                }

                if (isset($seen[$line->saleLineId])) {
                    throw new InvalidArgumentException("RefundBreakdown names sale line \"{$line->saleLineId}\" more than once.");
                }

                $seen[$line->saleLineId] = true;
                $sum = $sum->add($line->amount);
            }

            if (! $sum->equals($goods)) {
                throw new InvalidArgumentException("RefundBreakdown: the line amounts add up to {$sum->minorValue()}, not the goods component {$goods->minorValue()}.");
            }
        }
    }

    /** A refund that is all goods and has no per-line rows — what a plain amount means. */
    public static function goodsOnly(Money $amount): self
    {
        return new self($amount, Money::zero($amount->currency()), Money::zero($amount->currency()), Money::zero($amount->currency()));
    }

    public function total(): Money
    {
        return $this->goods->add($this->shipping)->add($this->adjustment)->subtract($this->deduction);
    }
}
