<?php

namespace App\Services;

use EasyCo\Pricing\Money;
use InvalidArgumentException;

/**
 * The ONE place operational-sales-domain-design.md §3.13's cumulative-
 * share formula is implemented — "Partial return of a quantity", stage
 * 6b-i. `App\Services\ReturnGoodsRecorder` is its first caller (D4); a
 * future `OrderRefunder` (order-lifecycle-design.md §7.3, stage 6b-ii)
 * reuses it unchanged for the PaymentRefund amount — one implementation,
 * every caller gets the same telescoping-sum guarantee.
 *
 * PURE AND STATELESS — no I/O, no repository, no container dependency of
 * any kind. An instantiable class with one public method, not a static
 * method: this project's other stateless-but-collaborator-shaped services
 * (OrderStatusChanger, OrderPaymentConfirmer) are autowired instances, and
 * matching that shape costs nothing here while keeping every future caller
 * consistent about how it resolves its collaborators — never mixing
 * `app(Foo::class)->bar()` with `Foo::bar()` for the same class of thing.
 *
 * THE FORMULA, EXACTLY (§3.13's own worked example):
 * `cumulative(n) = floor(netPaidAmount_minor * n / originalQuantity)`,
 * and the share for returning `k` more units on top of `r` already
 * returned is `cumulative(r + k) - cumulative(r)`. This is a telescoping
 * sum over integer breakpoints of the fixed interval `[0, originalQuantity]`
 * — proven in the design doc to sum to exactly `netPaidAmount` for ANY
 * sequence of consecutive partial returns, never a cent more or less,
 * regardless of how the return is split across multiple events.
 */
final class CumulativeRefundShareCalculator
{
    /**
     * @throws InvalidArgumentException If $thisReturn is not positive, if
     *   $alreadyReturned is negative, or if $alreadyReturned + $thisReturn
     *   would exceed $originalQuantity.
     */
    public function shareFor(Money $netPaidAmount, int $originalQuantity, int $alreadyReturned, int $thisReturn): Money
    {
        if ($thisReturn <= 0) {
            throw new InvalidArgumentException(
                "CumulativeRefundShareCalculator: thisReturn must be a positive integer, got {$thisReturn}."
            );
        }

        if ($alreadyReturned < 0) {
            throw new InvalidArgumentException(
                "CumulativeRefundShareCalculator: alreadyReturned must not be negative, got {$alreadyReturned}."
            );
        }

        if ($alreadyReturned + $thisReturn > $originalQuantity) {
            throw new InvalidArgumentException(sprintf(
                'CumulativeRefundShareCalculator: alreadyReturned (%d) + thisReturn (%d) = %d exceeds originalQuantity (%d).',
                $alreadyReturned,
                $thisReturn,
                $alreadyReturned + $thisReturn,
                $originalQuantity,
            ));
        }

        $cumulative = static fn (int $n): int => intdiv($netPaidAmount->minorValue() * $n, $originalQuantity);

        $share = $cumulative($alreadyReturned + $thisReturn) - $cumulative($alreadyReturned);

        return Money::fromMinorUnits($share, $netPaidAmount->currency());
    }
}
