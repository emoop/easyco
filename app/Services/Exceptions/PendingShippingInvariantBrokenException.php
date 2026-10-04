<?php

namespace App\Services\Exceptions;

use LogicException;

/**
 * The derived "shipping reduced so far" of a PENDING payment is below zero
 * (shipping-domain-design.md §7.2.15, §7.2.16): the pending payment holds MORE than
 * the order total less the goods already credited, which no return can produce. An
 * invariant is broken (a payment edited by hand, a REFUND line written outside a
 * return), and the refund is refused loudly instead of treating the figure as 0 and
 * letting a reduction through against numbers nobody can trust. A LogicException,
 * not a translated refusal: it is not something the merchant can correct in a form.
 * The message names ids and amounts (configuration and money, not personal data).
 */
final class PendingShippingInvariantBrokenException extends LogicException
{
    public function __construct(public readonly string $orderId, public readonly string $paymentId, public readonly int $reducedSoFarMinor)
    {
        parent::__construct(sprintf(
            'Order "%s": the shipping already reduced on pending payment "%s" derives as %d minor units, below zero — the payment holds more than the order total less the goods credited. Refusing to guess.',
            $orderId,
            $paymentId,
            $reducedSoFarMinor,
        ));
    }
}
