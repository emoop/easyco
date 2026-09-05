<?php

namespace EasyCo\Pricing\Persistence\Eloquent;

use EasyCo\Pricing\Contracts\ProductCostRepository;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\ProductCost;

/**
 * Maps the ProductCost entity onto pricing_product_costs. save()
 * finds-or-creates by id, same mutable-configuration shape
 * EloquentPriceListItemRepository already uses (as opposed to the
 * simpler find-or-create-by-id-only shape a fully immutable entity's
 * repository would need).
 */
final class EloquentProductCostRepository implements ProductCostRepository
{
    public function save(ProductCost $cost): void
    {
        $model = $cost->id() !== null
            ? ProductCostModel::findOrFail($cost->id())
            : new ProductCostModel();

        $model->priceable_id = $cost->priceableId();
        $model->cost_amount_minor = $cost->cost()->minorValue();
        $model->cost_currency = $cost->cost()->currency()->code();

        $model->save();

        if ($cost->id() === null) {
            $cost->assignId((string) $model->id);
        }
    }

    public function findByPriceableIdAndCurrency(string $priceableId, string $currency): ?ProductCost
    {
        $model = ProductCostModel::where('priceable_id', $priceableId)
            ->where('cost_currency', Currency::from($currency)->code())
            ->first();

        return $model !== null ? $this->toDomainProductCost($model) : null;
    }

    private function toDomainProductCost(ProductCostModel $model): ProductCost
    {
        return ProductCost::reconstituteFromStorage(
            id: (string) $model->id,
            priceableId: $model->priceable_id,
            cost: Money::fromMinorUnits($model->cost_amount_minor, $model->cost_currency),
        );
    }
}
