<?php

namespace App\Services;

use EasyCo\Payment\Enums\RefundChannel;
use EasyCo\Pricing\Money;
use InvalidArgumentException;

/**
 * What the merchant decided for ONE money-only refund ("refund without return",
 * shipping-domain-design.md §7.2.11): a shipping refund and/or an adjustment, a
 * MANDATORY free-text reason, the payout channel, and (from the dialog) an
 * operation key. There are no goods here — no SaleLine, no stock.
 *
 * A deduction is accepted only so that it can be REFUSED by name
 * (MoneyOnlyRefundRefusedException::DEDUCTION_NOT_ALLOWED): a deduction retains
 * revenue from goods, and there are none. Amounts are validated for shape only
 * (not negative); a blank reason is the service's translated refusal, not a
 * constructor error, so a form submitted without one gets a sentence, not a 500.
 */
final class MoneyOnlyRefundRequest
{
    public function __construct(
        public readonly Money $shipping,
        public readonly Money $adjustment,
        public readonly string $reason,
        public readonly RefundChannel $channel,
        public readonly ?string $operationKey = null,
        public readonly ?Money $deduction = null,
    ) {
        if ($operationKey !== null && (trim($operationKey) === '' || strlen($operationKey) > 64)) {
            throw new InvalidArgumentException('MoneyOnlyRefundRequest: the operation key must be 1 to 64 characters.');
        }

        foreach (['shipping' => $shipping, 'adjustment' => $adjustment, 'deduction' => $deduction] as $name => $amount) {
            if ($amount !== null && $amount->isNegative()) {
                throw new InvalidArgumentException("MoneyOnlyRefundRequest: {$name} must not be negative.");
            }
        }
    }
}
