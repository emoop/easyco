<?php

namespace App\NeedsAttention;

use App\Settings\StoreTimezone;
use DateTimeImmutable;
use DateTimeZone;
use EasyCo\Payment\Enums\PaymentRefundStatus;
use EasyCo\Payment\Enums\RefundChannel;
use EasyCo\Pricing\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Refunds owed for too long" (shipping-domain-design.md §7.2.20 §6): every refund whose status is
 * still OWED — money that was decided, dated and RECORDED, and has not left yet — oldest first.
 *
 * WHY NOT THROUGH OrderRefundsReader: that reader answers "what does THIS order owe", one order at a
 * time, from the refund rows it already has in hand. This is the opposite question — every order at
 * once — so it is one indexed read, and the facts it cannot get from payment_refunds alone (none: a
 * refund row already carries its order, its channel, its amount and its createdAt) are not needed.
 *
 * THE AGE IS A CALENDAR DAY COUNT IN THE STORE'S TIMEZONE, and it is the ONLY order this source
 * expresses (§7.2.7: the list judges nothing, so it may not rank by anything but age). created_at is
 * a UTC instant — the app's storage rule — so the day it began is asked of StoreTimezone, exactly as
 * a merchant would read the date off the refund; daysBetween() then counts whole calendar days to the
 * store's own today, so a refund OWED at 01:30 Sofia time is a day younger than a naive UTC count
 * would make it.
 *
 * ONE READ PER CALL: count() is a plain COUNT and page() is one indexed page — no per-row query, and
 * no eager loading of anything (a row needs no relation beyond what the refund row itself carries).
 */
final class OwedRefundSource implements NeedsAttentionSource
{
    public const KEY = 'owed_refund';

    /** What a refund row needs to become a row on this page — and nothing else is selected. */
    private const COLUMNS = ['id', 'order_id', 'amount_minor', 'amount_currency', 'channel', 'created_at'];

    public function __construct(
        private readonly StoreTimezone $timezone,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('needs_attention.sources.owed_refund.label');
    }

    public function count(): int
    {
        return $this->owed()->count();
    }

    public function page(int $page, int $perPage): array
    {
        $rows = $this->owed()
            // The index this read was given for (§7.2.20 §6, migration _000003 on payment_refunds):
            // (status, created_at) — status is the equality, created_at the range. A secondary index
            // carries the primary key, so id is the stable tie-breaker within one second for free.
            ->orderBy('created_at')
            ->orderBy('id')
            ->offset(max(0, $page - 1) * $perPage)
            ->limit($perPage)
            ->get(self::COLUMNS);

        $today = $this->timezone->today();
        $items = [];

        foreach ($rows as $row) {
            $startedOn = $this->timezone->dayOf($this->instant($row->created_at));
            $amount = Money::fromMinorUnits((int) $row->amount_minor, (string) $row->amount_currency);

            $items[] = new NeedsAttentionItem(
                sourceKey: self::KEY,
                orderId: (string) $row->order_id,
                fact: __('needs_attention.sources.owed_refund.fact', ['channel' => $this->channel($row->channel)]),
                amount: $amount,
                ageDays: StoreTimezone::daysBetween($startedOn, $today),
                startedOn: $startedOn,
            );
        }

        return $items;
    }

    /** The one read both halves of this source are built on. */
    private function owed(): Builder
    {
        return DB::table('payment_refunds')
            ->where('status', PaymentRefundStatus::OWED->value);
    }

    /**
     * created_at as an instant: the app stores UTC ('Y-m-d H:i:s'), and StoreTimezone is the one
     * place that turns that instant into the merchant's calendar day.
     */
    private function instant(mixed $createdAt): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $createdAt, new DateTimeZone('UTC'));
    }

    /**
     * The channel as a merchant would say it. The column is NOT NULL and written from RefundChannel,
     * but it is a free string in the database: an unrecognised value is named honestly ("an
     * unrecorded channel") rather than crashing a listing page over one legacy row.
     */
    private function channel(mixed $channel): string
    {
        $known = is_string($channel) ? RefundChannel::tryFrom($channel) : null;

        return __('needs_attention.channels.'.($known?->value ?? 'unknown'));
    }
}
