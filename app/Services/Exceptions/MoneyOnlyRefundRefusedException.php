<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * A money-only refund ("refund without return", shipping-domain-design.md §7.2.11)
 * was refused, by name: no reason was given; the order has no SETTLED payment, so
 * nothing has been paid and nothing can be paid back; a deduction was asked for
 * (a deduction retains revenue from goods, and there are none); or the shipping
 * and adjustment add up to nothing. The message is a translated sentence
 * (orders.money_only_refund.*); nothing has been written when this is thrown.
 */
final class MoneyOnlyRefundRefusedException extends RuntimeException
{
    public const REASON_REQUIRED = 'reason_required';

    public const PAYMENT_NOT_SETTLED = 'payment_not_settled';

    public const DEDUCTION_NOT_ALLOWED = 'deduction_not_allowed';

    public const NOTHING_TO_REFUND = 'nothing_to_refund';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(__('orders.money_only_refund.'.$reason));
    }
}
