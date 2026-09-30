<?php

namespace App\Services;

use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;

/**
 * What App\Services\OrderLineEditor::apply() actually wrote — the same
 * plain value-object shape App\Services\OrderRefundOutcome already uses:
 * a private constructor, one static factory, and reads that return exactly
 * what the editor built, never a second query.
 *
 * TWO READS, EACH WITH A REAL CALLER (order-editing-design.md §5):
 *
 * - transaction() is the ONE new Transaction holding every line this edit
 *   wrote (§5's own "one edit = one transaction"), and its id is what §5
 *   step 10 puts on the order_events row's transaction_id. Its own lines
 *   are reachable the ordinary way — $result->transaction()->saleLines() —
 *   which is why this class does not duplicate that read (see
 *   TransactionRepository's own docblock: lines are reachable through the
 *   Transaction aggregate, never through a general SaleLine repository).
 *
 * - resultingLines() is the order's CURRENT lines after the edit, in
 *   display order — exactly the list §4.4/§6 say the caller must recompute
 *   the order's totals from. The caller never re-queries for it: the
 *   editor already holds every one of these objects when it returns.
 *
 * The editor's caller (stage 3b) owns everything else §5 lists — the
 * order lock, the revision compare-and-set, the payments gate, the
 * promotion redistribution, Order::reviseTotals(), the payment void-and-
 * reissue, and the order_events row — none of which this result carries.
 */
final class OrderLineEditResult
{
    /**
     * @param  array<int, SaleLine>  $resultingLines
     */
    private function __construct(
        private readonly Transaction $transaction,
        private readonly array $resultingLines,
    ) {}

    /**
     * @param  array<int, SaleLine>  $resultingLines
     */
    public static function written(Transaction $transaction, array $resultingLines): self
    {
        return new self($transaction, $resultingLines);
    }

    public function transaction(): Transaction
    {
        return $this->transaction;
    }

    /**
     * @return array<int, SaleLine>
     */
    public function resultingLines(): array
    {
        return $this->resultingLines;
    }
}
