<?php

namespace EasyCo\Pricing\Persistence\Eloquent;

use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceListItem;

/**
 * Maps the PriceListItem entity onto pricing_price_list_items.
 *
 * WHY price_amount_minor/price_currency ARE READ FROM net()/gross()
 * RATHER THAN A RAW Money ACCESSOR: Price does not expose its
 * originally-constructed Money directly — only net()/gross()/tax(),
 * per Price's own docblock. To persist the item's price without
 * rounding drift, this repository reads whichever of net()/gross() is
 * the price's actual stored basis (net() when exclusiveOfTax, gross()
 * when inclusiveOfTax — the OTHER one is always the derived/rounded
 * value) and stores that exact minor-unit amount, alongside the tax
 * rate and inclusivity flag needed to reconstruct an equivalent Price
 * on read.
 */
final class EloquentPriceListItemRepository implements PriceListItemRepository
{
    public function save(PriceListItem $item): void
    {
        $model = $item->id() !== null
            ? PriceListItemModel::findOrFail($item->id())
            : new PriceListItemModel();

        $model->price_list_id = $item->priceListId();
        $model->target_type = $item->targetType()->value;
        $model->target_id = $item->targetId();
        $model->min_quantity = $item->minQuantity();

        $rawMoney = $item->price()->isTaxInclusive()
            ? $item->price()->gross()
            : $item->price()->net();

        $model->price_amount_minor = $rawMoney->minorValue();
        $model->price_currency = $rawMoney->currency()->code();
        $model->price_tax_rate_basis_points = $item->price()->taxRateBasisPoints();
        $model->price_tax_inclusive = $item->price()->isTaxInclusive();

        $model->save();

        if ($item->id() === null) {
            $item->assignId((string) $model->id);
        }
    }

    public function remove(string $itemId): void
    {
        PriceListItemModel::findOrFail($itemId)->delete();
    }

    /** @return PriceListItem[] */
    public function findByPriceListId(string $priceListId): array
    {
        return PriceListItemModel::where('price_list_id', $priceListId)
            ->get()
            ->map(fn (PriceListItemModel $model) => $this->toDomainItem($model))
            ->all();
    }

    private function toDomainItem(PriceListItemModel $model): PriceListItem
    {
        $money = Money::fromMinorUnits($model->price_amount_minor, Currency::of($model->price_currency));

        $price = $model->price_tax_inclusive
            ? Price::inclusiveOfTax($money, $model->price_tax_rate_basis_points)
            : Price::exclusiveOfTax($money, $model->price_tax_rate_basis_points);

        return PriceListItem::reconstituteFromStorage(
            id: (string) $model->id,
            priceListId: (string) $model->price_list_id,
            targetType: PriceListItemTargetType::from($model->target_type),
            targetId: $model->target_id,
            price: $price,
            minQuantity: $model->min_quantity,
        );
    }
}
