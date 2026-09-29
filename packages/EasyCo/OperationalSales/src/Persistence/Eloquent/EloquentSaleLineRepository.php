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
}
