<?php

namespace App\Services;

use EasyCo\Payment\Enums\RefundChannel;
use EasyCo\Pricing\Money;
use InvalidArgumentException;

/**
 * What the merchant decided for ONE refund, as OrderStatusChanger::cancel() /
 * recordReturn() take it (shipping-domain-design.md §7.2.1). Every field is
 * optional, and the DEFAULTS are exactly what the cancel/return dialog has
 * always done, so passing nothing changes nothing:
 *
 *  - goods per line: the computed share of each returned unit (a line absent
 *    from $enteredGoodsByLine), 0 being a legal entry;
 *  - shipping refund: 0;
 *  - deduction: 0 (and a deduction REQUIRES a reason);
 *  - channel: derived from the payment method (cash on delivery -> cash,
 *    otherwise bank).
 *
 * Until the new dialog (R3) and the caps (R1b) exist, a non-default request is
 * reachable only from tests. Amounts here are validated for shape only (not
 * negative); the caps are R1b's.
 */
final class RefundRequest
{
    /**
     * @param array<string, Money> $enteredGoodsByLine merchant-entered goods amount, keyed by the ORIGINAL sale line's id
     */
    public function __construct(
        public readonly array $enteredGoodsByLine = [],
        public readonly ?Money $shipping = null,
        public readonly ?Money $deduction = null,
        public readonly ?string $deductionReason = null,
        public readonly ?RefundChannel $channel = null,
    ) {
        foreach ($enteredGoodsByLine as $lineId => $amount) {
            if (! $amount instanceof Money || $amount->isNegative()) {
                throw new InvalidArgumentException("RefundRequest: the entered goods amount for line \"{$lineId}\" must be a non-negative Money.");
            }
        }

        foreach (['shipping' => $shipping, 'deduction' => $deduction] as $name => $amount) {
            if ($amount !== null && $amount->isNegative()) {
                throw new InvalidArgumentException("RefundRequest: {$name} must not be negative.");
            }
        }

        if ($deduction !== null && $deduction->isPositive() && trim((string) $deductionReason) === '') {
            throw new InvalidArgumentException('RefundRequest: a deduction requires a reason.');
        }
    }

    /** Today's behaviour: computed shares, no shipping, no deduction, derived channel. */
    public static function defaults(): self
    {
        return new self();
    }
}
