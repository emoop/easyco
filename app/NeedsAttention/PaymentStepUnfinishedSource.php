<?php

namespace App\NeedsAttention;

use App\Settings\StoreTimezone;
use DateTimeImmutable;
use DateTimeZone;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Pricing\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Orders whose payment step did not finish" (shipping stage 4h, shipping-domain-design.md §9.1, B9):
 * every payment that is PENDING with NO attempt date, not settled and not voided — the order was
 * placed and committed, and the charge attempt never completed (a crash, a provider that threw, a
 * Phase 2 that never ran). Oldest first, by the day the payment row was created.
 *
 * THE DISCRIMINATOR IS PAYMENT'S OWN (Payment's class docblock, "the two previously-indistinguishable
 * states"): status PENDING + attempted_at NULL means the attempt never completed, whereas PENDING +
 * attempted_at SET is a normal bank-transfer or cash-on-delivery order awaiting the customer's money —
 * and that one is NOT listed. It is exact because every writer of a Payment sets attempted_at once the
 * adapter answers: CheckoutOrchestrator (Phase 2) and PendingPaymentReissuer both do, and nothing else
 * creates payments. (A row written before attempted_at existed has it NULL too; the platform has no such
 * rows to speak of, and one that exists is honestly "we do not know".)
 *
 * "NOT SETTLED" IS ReceiptMismatchSource's own test, the stored generated column settled_order_id
 * (non-NULL exactly when status = captured OR confirmed_at is set), and "not voided" is voided_at.
 *
 * NOTHING IS STORED: the fact is derived on every read, like the other sources. The `order.placed`
 * listener failure that checkout now contains is NOT derivable from the database and is not on this
 * page (it is log-only).
 *
 * THE GRACE: a payment created a moment ago is legitimately PENDING with no attempt date while its own
 * request is still running Phase 2. Payments younger than GRACE_MINUTES are left out. That is the whole
 * purpose of the constant: it excludes requests STILL IN FLIGHT. It is not a severity threshold and not an
 * "overdue" rule (§7.2.7) — past the grace the page simply lists the fact, and the age column says how long.
 * It is measured on the app clock (now()), the clock that wrote created_at.
 *
 * ONE READ PER CALL: count() is one COUNT, page() one page — no per-row query.
 */
final class PaymentStepUnfinishedSource implements NeedsAttentionSource
{
    public const KEY = 'payment_step_unfinished';

    /** Requests still in flight are not unfinished; see the class docblock. NOT a severity threshold. */
    public const GRACE_MINUTES = 10;

    private const COLUMNS = ['id', 'order_id', 'amount_minor', 'amount_currency', 'created_at'];

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
        return __('needs_attention.sources.payment_step_unfinished.label');
    }

    public function count(): int
    {
        return $this->unfinished()->count();
    }

    public function page(int $page, int $perPage): array
    {
        $rows = $this->unfinished()
            ->orderBy('created_at')
            ->orderBy('id')
            ->offset(max(0, $page - 1) * $perPage)
            ->limit($perPage)
            ->get(self::COLUMNS);

        $today = $this->timezone->today();
        $items = [];

        foreach ($rows as $row) {
            $startedOn = $this->timezone->dayOf(new DateTimeImmutable((string) $row->created_at, new DateTimeZone('UTC')));

            $items[] = new NeedsAttentionItem(
                sourceKey: self::KEY,
                orderId: (string) $row->order_id,
                fact: __('needs_attention.sources.payment_step_unfinished.fact'),
                amount: Money::fromMinorUnits((int) $row->amount_minor, (string) $row->amount_currency),
                ageDays: StoreTimezone::daysBetween($startedOn, $today),
                startedOn: $startedOn,
            );
        }

        return $items;
    }

    /** The one read both halves of this source are built on. */
    private function unfinished(): Builder
    {
        return DB::table('payments')
            ->where('status', PaymentStatus::PENDING->value)
            ->whereNull('attempted_at')
            ->whereNull('voided_at')
            ->whereNull('settled_order_id')
            ->where('created_at', '<=', now()->subMinutes(self::GRACE_MINUTES)->format('Y-m-d H:i:s'));
    }
}
