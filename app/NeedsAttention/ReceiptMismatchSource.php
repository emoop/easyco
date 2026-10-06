<?php

namespace App\NeedsAttention;

use App\Services\PaymentReceiptReader;
use App\Services\PriceDisplayFormatter;
use App\Settings\StoreTimezone;
use EasyCo\Pricing\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * "Bank transfers that do not add up" (shipping-domain-design.md §7.2.20 §6): every bank-transfer
 * payment that is NOT settled and NOT voided whose EFFECTIVE receipts sum to something other than the
 * expected amount — money is in hand, and it is not reconciled. Oldest first, by the day its first
 * effective receipt arrived.
 *
 * THE SAME THREE FACTS PaymentReceiptReader USES, READ IN BULK: expected is payments.amount_minor,
 * received is the effective sum, and the difference is the one subtraction between them. This source
 * repeats none of that arithmetic differently — it performs it in SQL for every payment at once,
 * because the reader answers for ONE payment (it is handed a Payment) and this page asks about all of
 * them. Any disagreement between the two is impossible by construction: both read the same two columns
 * and the same effective-receipt definition.
 *
 * "EFFECTIVE" IS THE RECEIPT READER'S OWN RULE, in one NOT EXISTS: a receipt counts unless a LATER
 * receipt names it in supersedes_receipt_id (payment_receipts is append-only, a correction is a new
 * row).
 *
 * "SETTLED" IS THE DATABASE'S OWN COPY OF Payment::isSettled(): payments.settled_order_id is a stored
 * generated column that is NOT NULL exactly when status = 'captured' OR confirmed_at IS NOT NULL — so
 * one IS NULL test replaces both terms of that rule, and a payment a merchant settled by ACCEPTING a
 * mismatch leaves this list the moment he accepts it (acceptance writes confirmed_at; migration
 * _000002 on payments makes that a CHECK, not a convention).
 *
 * ONE READ PER CALL: count() is one COUNT over the same join, page() one page of it.
 */
final class ReceiptMismatchSource implements NeedsAttentionSource
{
    public const KEY = 'receipt_mismatch';

    public function __construct(
        private readonly StoreTimezone $timezone,
        private readonly PriceDisplayFormatter $formatter,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('needs_attention.sources.receipt_mismatch.label');
    }

    public function count(): int
    {
        return $this->mismatches()->count();
    }

    public function page(int $page, int $perPage): array
    {
        $rows = $this->mismatches()
            ->select([
                'payment.order_id',
                'payment.amount_minor',
                'received.received_minor',
                'received.amount_currency',
                'received.started_on',
            ])
            // Oldest first: the day the first effective receipt arrived, then the payment id — the
            // same order on every read, so a row cannot move between two pages of one listing.
            ->orderBy('received.started_on')
            ->orderBy('payment.id')
            ->offset(max(0, $page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $today = $this->timezone->today();
        $items = [];

        foreach ($rows as $row) {
            $currency = (string) $row->amount_currency;
            $expected = Money::fromMinorUnits((int) $row->amount_minor, $currency);
            $received = Money::fromMinorUnits((int) $row->received_minor, $currency);
            $difference = $received->subtract($expected);
            // received_on is a DATE in the store's own timezone (nothing to convert: the merchant
            // already named the day he saw the money), so only the WAIT is counted here.
            $startedOn = (string) $row->started_on;

            $items[] = new NeedsAttentionItem(
                sourceKey: self::KEY,
                orderId: (string) $row->order_id,
                fact: $this->fact($difference, $received, $expected),
                // The SIGNED difference: what the transfer is short of, or over by, as a figure.
                amount: $difference,
                ageDays: StoreTimezone::daysBetween($startedOn, $today),
                startedOn: $startedOn,
            );
        }

        return $items;
    }

    /**
     * The whole fact in one sentence, figures included: which way the money missed and by how much,
     * with what actually arrived and what was expected. Nothing is decided here — §7.2.1 owns the
     * accept/correct decision, and this page only has to show that it is waiting.
     */
    private function fact(Money $difference, Money $received, Money $expected): string
    {
        $sources = 'needs_attention.sources.receipt_mismatch.';

        return __($sources.($difference->isNegative() ? 'fact_short' : 'fact_over'), [
            // The sentence says "short by"/"over by" itself, so it takes the magnitude; the sign is
            // shown by the amount column, from the item's own signed amount.
            'difference' => $this->format(self::magnitude($difference)),
            'received' => $this->format($received),
            'expected' => $this->format($expected),
        ]);
    }

    /** The same amount without its sign (Money has no abs(): one constructor call is less API than one). */
    private static function magnitude(Money $amount): Money
    {
        return Money::fromMinorUnits(abs($amount->minorValue()), $amount->currency());
    }

    private function format(Money $amount): string
    {
        return $this->formatter->format($amount->decimalValue(), $amount->currency());
    }

    /** The one read both halves of this source are built on. */
    private function mismatches(): Builder
    {
        return DB::query()
            ->fromSub($this->effectiveReceipts(), 'received')
            ->join('payments as payment', function (JoinClause $join): void {
                // Currency is part of the join, not a filter: receipts are recorded in the payment's
                // own currency, and a group in any other currency has nothing to join to.
                $join->on('payment.id', '=', 'received.payment_id')
                    ->on('payment.amount_currency', '=', 'received.amount_currency');
            })
            ->where('payment.method', PaymentReceiptReader::BANK_TRANSFER)
            ->whereNull('payment.voided_at')
            ->whereNull('payment.settled_order_id')
            ->whereColumn('received.received_minor', '<>', 'payment.amount_minor');
    }

    /**
     * Every payment's effective receipts, summed, with the day the first of them arrived: one row per
     * (payment, currency), grouped in the database rather than read and added up in PHP — the same
     * grouped sum PaymentReceiptRepository::effectiveSum() performs for a single payment.
     */
    private function effectiveReceipts(): Builder
    {
        return DB::table('payment_receipts as receipt')
            ->selectRaw('receipt.payment_id as payment_id')
            ->selectRaw('receipt.amount_currency as amount_currency')
            ->selectRaw('SUM(receipt.amount_minor) as received_minor')
            ->selectRaw('MIN(receipt.received_on) as started_on')
            ->whereNotExists(function (Builder $superseding): void {
                // Mirrors EloquentPaymentReceiptRepository::effective(): a receipt counts unless a
                // LATER one names it. Spelled NOT EXISTS so the append-only table's own indexes answer
                // it (prc_supersedes_unique on the inner column, prc_payment_idx for the grouping).
                $superseding->selectRaw('1')
                    ->from('payment_receipts as superseding')
                    ->whereColumn('superseding.supersedes_receipt_id', 'receipt.id');
            })
            ->groupBy('receipt.payment_id', 'receipt.amount_currency');
    }
}
