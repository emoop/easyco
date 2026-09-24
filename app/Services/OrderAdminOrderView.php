<?php

namespace App\Services;

use EasyCo\Order\Order;
use EasyCo\Payment\Payment;

/**
 * The full read shape for one Order's admin View page — admin-panel-
 * design.md §14. Assembled entirely by OrderAdminReader::forOrder(); see
 * that method's own docblock for where each field actually comes from.
 *
 * clientName/channel are plain strings, never '' — OrderAdminReader
 * already substitutes '—' (D8) before constructing this DTO, so the View
 * page never needs its own null/blank-handling for these two.
 *
 * latestPayment IS NULLABLE — a genuinely possible, real state (D8): the
 * checkout that produced this Order could theoretically be missing its
 * Payment row only in data corrupted outside the normal write path
 * (CheckoutOrchestrator always writes one in the same DB transaction as
 * the Order itself — see that class's own docblock), but this DTO does
 * not assume that guarantee holds forever; a null here renders '—', not
 * an exception.
 */
final class OrderAdminOrderView
{
    /**
     * @param OrderAdminSaleLineView[] $lines
     */
    public function __construct(
        public readonly Order $order,
        public readonly string $clientName,
        public readonly string $channel,
        public readonly array $lines,
        public readonly bool $hasPromotionRedemption,
        public readonly ?Payment $latestPayment,
        public readonly int $paymentAttemptCount,
    ) {
    }
}
