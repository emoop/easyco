<?php

namespace App\Services;

use App\Enums\OrderEventType;
use EasyCo\OperationalSales\Contracts\SaleLineRepository;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\Order\Order;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * order-editing-design.md §4.4 — "the current lines of an order", resolved in
 * ONE place for both callers that need it (stage 4a extracted it from
 * OrderEditor): the editor, which edits them, and OrderAdminReader, which
 * shows them.
 *
 * THE RULE, ONCE. Every SALE line ever written for the order lives in the
 * placement transaction or in the transaction of an earlier edit (an
 * order_events row of type EDITED points at it — the join path §4.4 names;
 * an order carries no transaction list of its own). A line is REVERSED AWAY
 * by an edit when EDIT_REVERSAL lines against it sum to its whole quantity,
 * and it is then no longer one of the order's lines: its replacement, in the
 * edit's own transaction, is. Units RETURNED against a line are a different
 * fact — the line is still on the order, with fewer units left.
 *
 * TWO CALLERS, TWO QUESTIONS, ONE GATHER:
 *
 * - resolveRows() answers "which lines does this order have" — every line
 *   not edited away, INCLUDING a line whose every unit has been returned
 *   (an order that was returned in full must still list what it sold), as
 *   raw rows with each line's returned and edited-away sums. It reads raw
 *   rows on purpose, for the reason OrderAdminReader has always read them: a
 *   legacy SALE line (written before product_name/sku existed) makes
 *   SaleLine's own constructor throw, and a read model must render it, not
 *   crash on it.
 *
 * - resolve() answers "which lines can an edit act on" — the domain SaleLine
 *   objects for lines with remaining = quantity - returned - editedAway > 0,
 *   §4.4's formula exactly. It refuses what an edit cannot honestly work
 *   from (a partly-remaining line, a legacy line with no §3.13 snapshot).
 *
 * COST: a constant number of queries per order regardless of how many lines
 * it has — the EDITED-event read, one read of the lines, one grouped sum of
 * returned units, one grouped sum of edited-away units — plus, for
 * resolve() only, one transaction load per transaction that still holds a
 * current line (the placement one plus one per edit that left lines behind).
 * It resolves ONE order at a time; there is deliberately no many-orders form,
 * because no page needs one (the admin View page shows a single order).
 */
final class OrderCurrentLinesResolver
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly SaleLineRepository $saleLines,
    ) {}

    /**
     * Every line of the order that an edit has not reversed away, oldest
     * first (by id), as raw rows.
     *
     * @return array<int, array{row: object, returned: int, editedAway: int}>
     */
    public function resolveRows(Order $order): array
    {
        $transactionIds = $this->transactionIds($order);

        $rows = DB::table('operational_sales_sale_lines')
            ->whereIn('transaction_id', $transactionIds)
            ->where('type', SaleLineType::SALE->value)
            ->where('status', SaleLineStatus::COMPLETED->value)
            // A raw DB::table() read, so SaleLineModel's SoftDeletes scope
            // does not apply on its own: a soft-deleted line must be
            // excluded explicitly or a correction would keep rendering next
            // to its own original.
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        $ids = $rows->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        $returned = $this->saleLines->sumQuantityReturnedForOriginatingLines($ids);
        $editedAway = $this->saleLines->sumQuantityEditedAwayForOriginatingLines($ids);

        $lines = [];

        foreach ($rows as $row) {
            $id = (string) $row->id;
            $away = $editedAway[$id] ?? 0;

            // Reversed away by an edit: replaced by a line in a later
            // transaction, no longer one of the order's lines.
            if ($away >= (int) $row->quantity) {
                continue;
            }

            $lines[] = ['row' => $row, 'returned' => $returned[$id] ?? 0, 'editedAway' => $away];
        }

        return $lines;
    }

    /**
     * §4.4's current lines as domain SaleLines: remaining > 0, in the order
     * resolveRows() lists them.
     *
     * @return array<int, SaleLine>
     *
     * @throws InvalidArgumentException A transaction of the order is missing; a line is only partly remaining (an edit reverses a line in full and a return cannot exist before shipping, so no writer here produces one); a line carries no §3.13 snapshot, so totals cannot be recomputed from it.
     */
    public function resolve(Order $order): array
    {
        $current = [];

        foreach ($this->resolveRows($order) as $entry) {
            $quantity = (int) $entry['row']->quantity;
            $remaining = $quantity - $entry['returned'] - $entry['editedAway'];

            if ($remaining <= 0) {
                continue;
            }

            $id = (string) $entry['row']->id;

            if ($remaining !== $quantity) {
                throw new InvalidArgumentException(
                    "OrderEditor: line \"{$id}\" of order \"{$order->id()}\" is only partly remaining ({$remaining} of {$quantity}) — an anomaly, refused."
                );
            }

            $current[] = $entry['row'];
        }

        $linesById = $this->loadDomainLines($order, $current);

        $lines = [];

        foreach ($current as $row) {
            $id = (string) $row->id;
            $line = $linesById[$id] ?? throw new InvalidArgumentException(
                "OrderEditor: line \"{$id}\" of order \"{$order->id()}\" could not be loaded."
            );

            // Totals are recomputed from every current line, so a line with
            // no §3.13 snapshot (written before stage 4d) cannot be summed
            // honestly — refused for the whole order, never guessed at.
            if ($line->netPaidAmount() === null || $line->promotionDiscountShare() === null
                || $line->discretionaryDiscount() === null || $line->finalUnitPrice() === null
                || $line->regularUnitPrice() === null || $line->priceableId() === null) {
                throw new InvalidArgumentException(
                    "OrderEditor: line \"{$id}\" of order \"{$order->id()}\" carries no §3.13 snapshot, so the order's totals cannot be recomputed from it. Such an order is not edited."
                );
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Every line of the order that an edit has not reversed away — the same
     * set resolveRows() lists, including a line whose units have been partly
     * or wholly returned — as domain SaleLines, in the same order. This is
     * what a cancellation or a return works from (OrderStatusChanger): it
     * applies its own R7 remaining arithmetic per line, so unlike resolve()
     * it must NOT drop a fully returned line or refuse a partly returned one
     * (both are normal once an order has shipped), and it makes no claim about
     * §3.13 snapshots — a legacy line fails to load exactly as it always did.
     *
     * @return array<int, SaleLine>
     */
    public function resolveWithReturns(Order $order): array
    {
        $rows = array_column($this->resolveRows($order), 'row');
        $linesById = $this->loadDomainLines($order, $rows);

        return array_map(
            static fn (object $row): SaleLine => $linesById[(string) $row->id],
            $rows,
        );
    }

    /**
     * Loads the domain SaleLines behind the given rows — only the
     * transactions that hold one of them, one load each.
     *
     * @param  array<int, object>  $rows
     * @return array<string, SaleLine> keyed by line id
     */
    private function loadDomainLines(Order $order, array $rows): array
    {
        $byTransaction = [];
        foreach ($rows as $row) {
            $byTransaction[(string) $row->transaction_id] = true;
        }

        $linesById = [];

        foreach (array_keys($byTransaction) as $transactionId) {
            $transaction = $this->transactions->findByIdWithSaleLines($transactionId);

            if ($transaction === null) {
                throw new InvalidArgumentException(
                    "OrderEditor: transaction \"{$transactionId}\" of order \"{$order->id()}\" does not exist."
                );
            }

            foreach ($transaction->saleLines() as $line) {
                $linesById[(string) $line->id()] = $line;
            }
        }

        foreach ($rows as $row) {
            if (! isset($linesById[(string) $row->id])) {
                throw new InvalidArgumentException(
                    "OrderEditor: line \"{$row->id}\" of order \"{$order->id()}\" could not be loaded."
                );
            }
        }

        return $linesById;
    }

    /**
     * The placement transaction, then each earlier edit's own, oldest first.
     *
     * @return array<int, string>
     */
    private function transactionIds(Order $order): array
    {
        $ids = [$order->transactionId()];

        $editIds = DB::table('order_events')
            ->where('order_id', $order->id())
            ->where('type', OrderEventType::EDITED->value)
            ->whereNotNull('transaction_id')
            ->orderBy('id')
            ->pluck('transaction_id');

        foreach ($editIds as $id) {
            $ids[] = (string) $id;
        }

        return $ids;
    }
}
