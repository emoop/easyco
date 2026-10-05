<?php

namespace App\Services;

use App\Enums\OrderEventType;
use DateTimeImmutable;
use EasyCo\Order\Enums\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * THE ONE READER of the facts a shop's own return policy depends on
 * (shipping-domain-design.md §7.2.6, refunds R3): for an order, its delivery date (the
 * `delivered` status change in its history), and for each return its recorded date, the
 * date the customer announced it, and the days between the delivery and each.
 *
 * FACTS, NOT RULES. It reads and reports; no deadline exists in this class or anywhere it
 * is called from, and no fact ever blocks a return or a refund.
 *
 * ONE query: the `returned` events and the `delivered` status change of the order, nothing
 * else, oldest first. A cancellation also writes a `returned` event (it returns the goods),
 * so it appears here with no announced date — and, if the order was never delivered, no
 * day counts. An order with no delivery event has deliveredAt null and no day figure.
 * (If an order were ever delivered twice the FIRST delivery stands; the lifecycle allows
 * one.)
 */
final class OrderReturnFactsReader
{
    public function forOrder(string $orderId): OrderReturnFacts
    {
        $rows = DB::table('order_events')
            ->where('order_id', $orderId)
            ->where(function ($query): void {
                $query->where('type', OrderEventType::RETURNED->value)
                    ->orWhere(function ($delivered): void {
                        $delivered->where('type', OrderEventType::STATUS_CHANGED->value)
                            ->where('to_status', OrderStatus::DELIVERED->value);
                    });
            })
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['id', 'type', 'transaction_id', 'occurred_at', 'announced_return_at']);

        $deliveredAt = null;

        foreach ($rows as $row) {
            if ($row->type === OrderEventType::STATUS_CHANGED->value) {
                $deliveredAt = new DateTimeImmutable((string) $row->occurred_at);

                break;
            }
        }

        $returns = [];

        foreach ($rows as $row) {
            if ($row->type !== OrderEventType::RETURNED->value) {
                continue;
            }

            $recordedAt = new DateTimeImmutable((string) $row->occurred_at);
            $announcedAt = $row->announced_return_at === null ? null : new DateTimeImmutable((string) $row->announced_return_at);

            $returns[] = new OrderReturnFact(
                eventId: (string) $row->id,
                transactionId: $row->transaction_id === null ? null : (string) $row->transaction_id,
                recordedAt: $recordedAt,
                announcedAt: $announcedAt,
                daysFromDeliveryToAnnounced: $deliveredAt !== null && $announcedAt !== null ? OrderReturnFacts::wholeDays($deliveredAt, $announcedAt) : null,
                daysFromDeliveryToRecorded: $deliveredAt !== null ? OrderReturnFacts::wholeDays($deliveredAt, $recordedAt) : null,
            );
        }

        return new OrderReturnFacts($deliveredAt, $returns);
    }
}
