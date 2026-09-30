<?php

namespace EasyCo\OperationalSales\Contracts;

/**
 * A minimal, deliberately narrow read contract — two methods, not a general
 * SaleLine repository. SaleLines are otherwise reachable only through
 * TransactionRepository::findByIdWithSaleLines() (the aggregate boundary);
 * this exists purely to answer R7 (order-lifecycle-design.md §2.2) without
 * loading every SaleLine of every Transaction that has ever touched a
 * given originating line. Do not grow this into a general SaleLine
 * repository — a new query need is a new, equally narrow method, never a
 * reason to add findById()/findByTransactionId()/etc. here.
 *
 * THE SECOND METHOD IS THE FIRST ONE'S BATCHED TWIN, and it is here for the
 * reason that rule anticipates rather than in spite of it: the Orders admin
 * View page (§8.4) needs R7's number for EVERY line of ONE order, and calling
 * the single-line method in a loop would be the N+1 that
 * admin-panel-design.md §14's D1/D7 discipline exists to forbid. So the loop
 * becomes one grouped query — the same read, stated once for one line and once
 * for many, never a third shape.
 */
interface SaleLineRepository
{
    /**
     * Sums quantity_returned across every REFUND SaleLine sharing
     * $originatingSaleLineId — R7's own "already returned" read
     * (order-lifecycle-design.md §2.2), a query, never a stored running
     * counter (operational-sales-domain-design.md §3.4 revised). Zero
     * when the line has never been refunded at all.
     */
    public function sumQuantityReturnedForOriginatingLine(string $originatingSaleLineId): int;

    /**
     * order-editing-design.md §4.5, stage 2 (D6) — the same shape as
     * sumQuantityReturnedForOriginatingLine() above, but sums
     * quantity_returned across EDIT_REVERSAL-type lines only (never
     * REFUND): "how much of this line has been edited away," a sum kept
     * genuinely independent of "how much has been returned" (§4.4's own
     * "temporally disjoint" reasoning — editing is legal only at
     * placed/confirmed, a return only at shipped/delivered, so at most one
     * of the two sums is ever non-zero for a given line). Zero when the
     * line has never had an edit reverse any of it.
     *
     * NO BATCHED/PLURAL SIBLING IN THIS STAGE, DELIBERATELY: the plural
     * sumQuantityReturnedForOriginatingLines() above was only added later,
     * once a real admin-UI page needed to avoid N+1 across many lines at
     * once (§8.4) — this stage has no such caller yet, so one is not built
     * ahead of a real need.
     */
    public function sumQuantityEditedAwayForOriginatingLine(string $originatingSaleLineId): int;

    /**
     * The same sum for MANY originating lines at once, keyed by
     * originating_sale_line_id — ONE query for the whole set (grouped, not
     * per id), so §8.4's per-line returnable quantity costs the View page
     * a constant amount rather than one read per rendered line.
     *
     * A line with NO REFUND lines is simply ABSENT from the returned map —
     * never present with a 0 — which puts the "zero means never refunded"
     * default in the caller's hands (OrderAdminReader does it with a single
     * `?? 0`) instead of making this method materialise rows it never read.
     * An EMPTY $originatingSaleLineIds returns [] and issues NO query at all:
     * an order with no lines has nothing to ask about.
     *
     * @param  array<int, string>  $originatingSaleLineIds
     * @return array<string, int> originatingSaleLineId => sum of quantity_returned (only for ids that have REFUND lines)
     */
    public function sumQuantityReturnedForOriginatingLines(array $originatingSaleLineIds): array;

    /**
     * order-editing-design.md §4.4/§5 step 6, stage 3b — the batched twin of
     * sumQuantityEditedAwayForOriginatingLine(), added for the reason this
     * interface's own opening paragraph gives for the REFUND twin: the order
     * editor needs "how much of this line has been edited away" for EVERY
     * candidate line of ONE order, and a per-line loop would be the N+1 the
     * edit's own query-count test forbids. Same read, one grouped query.
     *
     * Same map shape as sumQuantityReturnedForOriginatingLines(): a line
     * never edited away is ABSENT (never 0), and an empty id list returns []
     * with no query.
     *
     * @param  array<int, string>  $originatingSaleLineIds
     * @return array<string, int> originatingSaleLineId => sum of quantity_returned across its EDIT_REVERSAL lines
     */
    public function sumQuantityEditedAwayForOriginatingLines(array $originatingSaleLineIds): array;
}
