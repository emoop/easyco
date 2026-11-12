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
     * The products ONE scope row of a price list matches: brand, category, tag, SEASON, COLLECTION (P6e-3b, the pivot; the switch is not asked: a refresh of a switched-off scope is harmless), product or attribute value. A customer-group or channel scope never matches in the storefront (it has no customer
     * group or channel), so it matches no product.
     *
     * @return list<string>
     */
    public function productsMatching(PriceListScopeType|string $type, string $referenceId): array
    {
        $type = $type instanceof PriceListScopeType ? $type : PriceListScopeType::from($type);

        $ids = match ($type) {
            PriceListScopeType::BRAND => DB::table('catalog_products')->where('brand_id', $referenceId)->pluck('id'),
            PriceListScopeType::SEASON => DB::table('catalog_products')->where('season_id', $referenceId)->pluck('id'),
            PriceListScopeType::CATEGORY => DB::table('catalog_product_categories')->where('category_id', $referenceId)->pluck('product_id'),
            PriceListScopeType::TAG => DB::table('catalog_product_tags')->where('tag_id', $referenceId)->pluck('product_id'),
            PriceListScopeType::COLLECTION => DB::table('catalog_product_collections')->where('collection_id', $referenceId)->pluck('product_id'),
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
     * The products a SET of scope rows can touch, by the resolver rule (P6b, like the storefront filters): the rows of ONE kind are OR-ed (their products are UNITED), the different kinds are AND-ed (the
     * kinds sets are INTERSECTED). With no row at all the list touches EVERY product (null).
     *
     * @param list<array{0: PriceListScopeType|string, 1: string}> $rows [kind, reference id] pairs
     * @return list<string>|null null = all products
     */
    public function productsOfScopes(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        $unionByKind = [];

        foreach ($rows as [$kind, $reference]) {
            $kindName = $kind instanceof PriceListScopeType ? $kind->value : (string) $kind;
            $unionByKind[$kindName] ??= [];

            foreach ($this->productsMatching($kind, (string) $reference) as $id) {
                $unionByKind[$kindName][$id] = true;
            }
        }

        $touched = null;

        foreach ($unionByKind as $union) {
            $ids = array_map('strval', array_keys($union));
            $touched = $touched === null ? $ids : array_values(array_intersect($touched, $ids));
        }

        return $touched ?? [];
    }

    /**
     * The products a stored price list can touch (its scope rows through productsOfScopes()); null = all products.
     *
     * @return list<string>|null
     */
    public function productsOfList(string $priceListId): ?array
    {
        $rows = DB::table('pricing_price_list_scopes')->where('price_list_id', $priceListId)->get(['scope_type', 'scope_reference_id'])
            ->map(fn ($row): array => [(string) $row->scope_type, (string) $row->scope_reference_id])->all();

        return $this->productsOfScopes($rows);
    }

    /**
     * The products whose price can change when ONE scope row is attached to or detached from a list (the row has the kind K):
     *  - the list has no OTHER row: it applied to everything before (attach) or applies to everything after (detach) → every product (null);
     *  - K already has other rows: the OR widens or narrows K only → the products the row matches, within what the other kinds allow;
     *  - K is a NEW kind: the AND narrows or widens everything the OTHER kinds allow → the products the other kinds touch.
     *
     * @return list<string>|null null = all products
     */
    public function productsOfScopeRowChange(string $priceListId, string $scopeId, PriceListScopeType|string $kind, string $referenceId): ?array
    {
        $kind = $kind instanceof PriceListScopeType ? $kind : PriceListScopeType::from($kind);
        $others = DB::table('pricing_price_list_scopes')->where('price_list_id', $priceListId)->where('id', '!=', $scopeId)->get(['scope_type', 'scope_reference_id'])
            ->map(fn ($row): array => [(string) $row->scope_type, (string) $row->scope_reference_id])->all();

        if ($others === []) {
            return null;
        }

        $otherKindRows = array_values(array_filter($others, fn (array $row): bool => $row[0] !== $kind->value));
        $touchedByOtherKinds = $this->productsOfScopes($otherKindRows);

        if (count($otherKindRows) === count($others)) {
            return $touchedByOtherKinds ?? [];
        }

        $ofRow = $this->productsMatching($kind, $referenceId);

        return $touchedByOtherKinds === null ? $ofRow : array_values(array_intersect($ofRow, $touchedByOtherKinds));
    }

    /** Refreshes what a price list can touch: its products, or ALL when it has no scope. */
    public function priceList(string $priceListId): void
    {
        $ids = $this->productsOfList($priceListId);

        $ids === null ? $this->all() : $this->products($ids);
    }

    /** Refreshes what ONE scope row attach / detach can change (see productsOfScopeRowChange()). */
    public function priceListScopeChanged(string $priceListId, string $scopeId, PriceListScopeType|string $kind, string $referenceId): void
    {
        $ids = $this->productsOfScopeRowChange($priceListId, $scopeId, $kind, $referenceId);

        $ids === null ? $this->all() : $this->products($ids);
    }
}
