<?php

namespace EasyCo\OperationalSales\Persistence\Eloquent;

use EasyCo\OperationalSales\Contracts\SaleLineRepository;
use EasyCo\OperationalSales\Enums\SaleLineType;

final class EloquentSaleLineRepository implements SaleLineRepository
{
    public function sumQuantityReturnedForOriginatingLine(string $originatingSaleLineId): int
    {
        return (int) SaleLineModel::query()
            ->where('type', SaleLineType::REFUND->value)
            ->where('originating_sale_line_id', $originatingSaleLineId)
            ->sum('quantity_returned');
    }

    /**
     * order-editing-design.md §4.5, stage 2 (D6) — mirrors
     * sumQuantityReturnedForOriginatingLine() exactly, summing
     * EDIT_REVERSAL-type lines instead of REFUND-type ones. Same
     * SoftDeletes-scope reasoning applies unchanged: a soft-deleted
     * EDIT_REVERSAL line (a correction) must stop counting toward "how
     * much was edited away," and reading through SaleLineModel::query()
     * gets that for free.
     */
    public function sumQuantityEditedAwayForOriginatingLine(string $originatingSaleLineId): int
    {
        return (int) SaleLineModel::query()
            ->where('type', SaleLineType::EDIT_REVERSAL->value)
            ->where('originating_sale_line_id', $originatingSaleLineId)
            ->sum('quantity_returned');
    }

    /**
     * The same condition, grouped — see the contract's own docblock for why
     * this exists ([], and no query, for an empty id list; an id with no
     * REFUND lines absent rather than 0).
     *
     * selectRaw() IS WHAT MAKES IT ONE QUERY: a plain ->sum() per id would be
     * the N+1 §8.4 must not cost, and `selectRaw(...)->pluck('total', 'id')`
     * reads the grouped aggregate straight back — no ->get() of rows that are
     * then thrown away, and no raw PHP summing of every REFUND line a large
     * order ever produced. SUM() returns NULL/string through the driver, hence
     * the (int) cast, exactly as the single-line method above does it.
     *
     * The ids are compared as strings against the stored BIGINT column, which
     * MySQL coerces (the same way OrderAdminReader's own sale-line read
     * compares `priceable_id` strings). The WHERE is seekable: the column is a
     * real `foreignId(...)->constrained()` FK (2026_08_25_000004's own
     * migration), so MySQL maintains an index on it for the constraint — an
     * IMPLICIT one, not one this code names, which is worth saying so nobody
     * reads "there is no index migration" as "this is a table scan".
     *
     * THE SoftDeletes SCOPE IS NOT BYPASSED HERE, DELIBERATELY, AND IT DIFFERS
     * FROM OrderAdminReader's OWN RAW READ: a REFUND line is the record that
     * its units came back, so a soft-deleted REFUND line (a correction) must
     * stop counting toward "already returned" — the same reason
     * OrderAdminReader excludes soft-deleted SALE lines from the Lines table.
     * Reading through SaleLineModel::query() gets that rule for free, which is
     * one of the two reasons this method lives in the package next to the
     * model instead of in the app layer.
     */
    public function sumQuantityReturnedForOriginatingLines(array $originatingSaleLineIds): array
    {
        if ($originatingSaleLineIds === []) {
            return [];
        }

        return SaleLineModel::query()
            ->where('type', SaleLineType::REFUND->value)
            ->whereIn('originating_sale_line_id', $originatingSaleLineIds)
            ->groupBy('originating_sale_line_id')
            ->selectRaw('originating_sale_line_id, SUM(quantity_returned) AS returned_quantity')
            ->pluck('returned_quantity', 'originating_sale_line_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * order-editing-design.md §4.4/§5 step 6, stage 3b — sumQuantityEdited
     * AwayForOriginatingLine() grouped, exactly as
     * sumQuantityReturnedForOriginatingLines() above is the grouped form of
     * its own single-line sibling (see the contract's docblock).
     */
    public function sumQuantityEditedAwayForOriginatingLines(array $originatingSaleLineIds): array
    {
        if ($originatingSaleLineIds === []) {
            return [];
        }

        return SaleLineModel::query()
            ->where('type', SaleLineType::EDIT_REVERSAL->value)
            ->whereIn('originating_sale_line_id', $originatingSaleLineIds)
            ->groupBy('originating_sale_line_id')
            ->selectRaw('originating_sale_line_id, SUM(quantity_returned) AS edited_quantity')
            ->pluck('edited_quantity', 'originating_sale_line_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
    }
}
