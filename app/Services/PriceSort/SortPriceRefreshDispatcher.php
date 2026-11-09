<?php

namespace App\Services\PriceSort;

use App\Jobs\RefreshProductSortPrices;
use EasyCo\Pricing\Enums\PriceListScopeType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * P6a: the ONE place that queues a refresh of the stored sort prices: one product, a set of products (in chunks of ProductSortPriceRefresher::CHUNK) or ALL products.
 * Every trigger (SortPriceTriggers, the stock decorator, the boundary command) goes through here, so the unique-job rule and the chunk size live once.
 */
final class SortPriceRefreshDispatcher
{
    public function product(int|string $productId): void
    {
        $this->push([(string) $productId]);
    }

    /**
     * Queues ONE refresh job. A queue that is down must never break the write that caused the refresh (a checkout, an admin save): the failure is LOGGED and swallowed, and the nightly `prices:refresh-from`
     * repairs the value.
     *
     * @param list<string> $productIds
     */
    private function push(array $productIds): void
    {
        try {
            RefreshProductSortPrices::dispatch($productIds);
        } catch (Throwable $exception) {
            Log::error('The sort-price refresh could not be queued; the nightly pass will repair it.', ['products' => count($productIds), 'exception' => $exception->getMessage()]);
        }
    }

    /** @param list<int|string> $productIds */
    public function products(array $productIds): void
    {
        $ids = array_values(array_unique(array_map('strval', $productIds)));

        foreach (array_chunk($ids, ProductSortPriceRefresher::CHUNK) as $chunk) {
            $this->push($chunk);
        }
    }

    /** Every product, in chunks (an unscoped price list, the safety net after a long scheduler stop). */
    public function all(): void
    {
        $last = 0;

        while (true) {
            $ids = DB::table('catalog_products')->where('id', '>', $last)->orderBy('id')->limit(ProductSortPriceRefresher::CHUNK)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $last = (int) end($ids);
            $this->push(array_map('strval', $ids));
        }
    }

    /**
     * The products ONE scope row of a price list can touch: brand, category, tag, product or attribute value. A customer-group or channel scope never matches in the storefront
     * (it has no customer group or channel), so it touches no product.
     *
     * @return list<string>
     */
    public function productsMatching(PriceListScopeType|string $type, string $referenceId): array
    {
        $type = $type instanceof PriceListScopeType ? $type : PriceListScopeType::from($type);

        $ids = match ($type) {
            PriceListScopeType::BRAND => DB::table('catalog_products')->where('brand_id', $referenceId)->pluck('id'),
            PriceListScopeType::CATEGORY => DB::table('catalog_product_categories')->where('category_id', $referenceId)->pluck('product_id'),
            PriceListScopeType::TAG => DB::table('catalog_product_tags')->where('tag_id', $referenceId)->pluck('product_id'),
            PriceListScopeType::PRODUCT => DB::table('catalog_products')->where('id', $referenceId)->pluck('id'),
            PriceListScopeType::ATTRIBUTE_VALUE => DB::table('catalog_variation_attribute_values as vav')
                ->join('catalog_variations as v', 'v.id', '=', 'vav.variation_id')
                ->where('vav.attribute_value_id', $referenceId)
                ->pluck('v.product_id'),
            PriceListScopeType::CUSTOMER_GROUP, PriceListScopeType::CHANNEL => collect(),
        };

        return $ids->map(fn ($id): string => (string) $id)->unique()->values()->all();
    }

    /**
     * The products a price list can touch. A list's scope rows are AND-ed (pricing-persistence-domain-design.md §4.1: a list scoped to a brand AND a tag matches only products that are both), so the
     * products it can touch are the INTERSECTION of what its scopes match; with no scope at all it touches EVERY product (null). A customer-group / channel scope never matches in the storefront, so it
     * makes the set empty.
     *
     * $exceptScopeId leaves one scope row out: when a scope row is attached or detached, the products whose price can change are those the OTHER scopes touch (the changed row only narrows or widens them).
     *
     * @return list<string>|null null = all products
     */
    public function productsOfList(string $priceListId, ?string $exceptScopeId = null): ?array
    {
        $scopes = DB::table('pricing_price_list_scopes')->where('price_list_id', $priceListId)
            ->when($exceptScopeId !== null, fn ($query) => $query->where('id', '!=', $exceptScopeId))
            ->get(['scope_type', 'scope_reference_id']);

        if ($scopes->isEmpty()) {
            return null;
        }

        $touched = null;

        foreach ($scopes as $scope) {
            $matching = $this->productsMatching((string) $scope->scope_type, (string) $scope->scope_reference_id);
            $touched = $touched === null ? $matching : array_values(array_intersect($touched, $matching));
        }

        return $touched ?? [];
    }
    /** Refreshes what a price list can touch: its products, or ALL when it has no scope. */
    public function priceList(string $priceListId, ?string $exceptScopeId = null): void
    {
        $ids = $this->productsOfList($priceListId, $exceptScopeId);

        $ids === null ? $this->all() : $this->products($ids);
    }
}
