<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * A cancellation or return on a PENDING (not yet paid) payment was given a
 * decision that only makes sense for money that was actually paid
 * (shipping-domain-design.md §7.2.4): a deduction, or a goods amount other than
 * the computed share. Nothing is paid, so there is nothing to deduct from and
 * nothing to bargain over; the goods amount is read-only there. Thrown inside the
 * order-locked transaction, so the whole operation rolls back.
 */
final class PendingPaymentRefundRuleException extends RuntimeException
{
    public const DEDUCTION = 'deduction';

    public const GOODS = 'goods';

    private function __construct(private readonly string $rule)
    {
        parent::__construct(__('orders.pending_payment_rules.'.$rule));
    }

    public static function deductionNotAllowed(): self
    {
        return new self(self::DEDUCTION);
    }

    public static function goodsAmountIsReadOnly(): self
    {
        return new self(self::GOODS);
    }

    /** One of the rule constants. */
    public function rule(): string
    {
        return $this->rule;
    }
}
