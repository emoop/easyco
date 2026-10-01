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
 *
 * events IS THE ORDER'S OWN HISTORY (order-lifecycle-design.md §6.3, §10
 * stage 3): every order_events row for this order, oldest first, read in one
 * query by forOrder() and never by the Orders LIST (which stays a read with no
 * per-row events query — §11 item 15). [] is the normal state for every order
 * placed before this table existed, never an error. NOTHING RENDERS IT YET: the
 * timeline section is §10 stage 7, so this is a read with no consumer until
 * then, deliberately.
 */
final class OrderAdminOrderView
{
    /**
     * @param OrderAdminSaleLineView[] $lines
     * @param OrderAdminEventView[] $events
     * @param Payment[] $payments EVERY payment row of the order (the current one included), read in one query
     */
    public function __construct(
        public readonly Order $order,
        public readonly string $clientName,
        public readonly string $channel,
        public readonly array $lines,
        public readonly bool $hasPromotionRedemption,
        public readonly ?Payment $latestPayment,
        public readonly array $payments,
        public readonly array $events,
    ) {
    }
}
