<?php

namespace App\Services\PriceSort;

use App\Services\CatalogScopeResolver;
use App\Storefront\Reader\AvailablePriceRule;
use App\Storefront\Visibility\StorefrontVisibility;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Inventory\Persistence\Eloquent\StockLevelModel;
use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Contracts\PriceRangeResolver;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\PriceRange;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * P6a: recomputes `catalog_products.price_from_minor`, the stored price a listing is SORTED BY (what the customer pays never depends on it).
 *
 * THE RULE is App\Storefront\Reader\AvailablePriceRule — the one the card and the product page use too: the lowest final price among the product's AVAILABLE
 * shown variations (priced, in stock), the lowest among all its priced shown variations when none is available, NULL when nothing is priced. The shown variations are
 * StorefrontVisibility's, the prices come ONLY through the existing contracts (CatalogScopeResolver::forVariations() + PriceRangeResolver::resolveQuotes(), the pair the card
 * reads), so a replaced resolver is honoured and the value equals the card's «от» price.
 *
 * P6c: the SAME pass also keeps `catalog_products.is_available` (does the product have a SHOWN variation with stock > 0?). It comes from the same two reads (the shown variations and their stock): no query of its own,
 * and the changed rows of BOTH columns are written by the ONE statement below. The "sold out" fact does not need prices: a product with no price can still be available.
 *
 * A CHUNK COSTS A CONSTANT NUMBER OF QUERIES whatever the number of products: the shown variations (1), the stock (1), the scope inputs and the prices (the resolvers' fixed few),
 * the current values (1) and ONE write for all the changed rows. Only rows whose value changed are written; running it twice writes nothing the second time (idempotent).
 * The resolvers are made fresh for every chunk: a queue worker lives for days and must never serve a cached price list.
 */
final class ProductSortPriceRefresher
{
    /** Products per chunk (the nightly pass and the "all products" fast path). */
    public const CHUNK = 200;

    public function __construct(private readonly StorefrontVisibility $visibility)
    {
    }

    /**
     * @param list<int|string> $productIds
     * @return int how many rows were written (changed)
     */
    public function refresh(array $productIds): int
    {
        $ids = array_values(array_unique(array_map('strval', $productIds)));

        if ($ids === []) {
            return 0;
        }

        $shown = $this->visibility->variations(VariationModel::query())
            ->whereIn('catalog_variations.product_id', $ids)
            ->get(['catalog_variations.id', 'catalog_variations.product_id']);

        $variationsByProduct = [];

        foreach ($shown as $variation) {
            $variationsByProduct[(string) $variation->product_id][] = (string) $variation->id;
        }

        $allVariationIds = array_merge([], ...array_values($variationsByProduct));
        $stock = $this->stock($allVariationIds);
        $quotes = $this->quotes($allVariationIds);

        $current = DB::table('catalog_products')->whereIn('id', $ids)->get(['id', 'price_from_minor', 'is_available'])->keyBy('id');
        $changes = [];

        foreach ($ids as $id) {
            $row = $current->get($id) ?? $current->get((int) $id);

            if ($row === null) {
                continue; // the product no longer exists
            }

            $newPrice = $this->priceOf($variationsByProduct[$id] ?? [], $quotes, $stock);
            $newAvailable = self::availableOf($variationsByProduct[$id] ?? [], $stock);
            $oldPrice = $row->price_from_minor === null ? null : (int) $row->price_from_minor;

            if ($oldPrice !== $newPrice || (bool) $row->is_available !== $newAvailable) {
                $changes[$id] = [$newPrice, $newAvailable];
            }
        }

        if ($changes === []) {
            return 0;
        }

        // ONE statement for every changed row and BOTH columns (two CASEs), whatever their number; updated_at is not touched (derived columns)
        $cases = str_repeat('WHEN ? THEN ? ', count($changes));
        $bindings = [];

        foreach ($changes as $id => [$price]) {
            $bindings[] = (int) $id;
            $bindings[] = $price;
        }

        foreach ($changes as $id => [, $available]) {
            $bindings[] = (int) $id;
            $bindings[] = $available ? 1 : 0;
        }

        $bindings = array_merge($bindings, array_map('intval', array_keys($changes)));
        $in = implode(',', array_fill(0, count($changes), '?'));

        DB::update("UPDATE catalog_products SET price_from_minor = CASE id {$cases}END, is_available = CASE id {$cases}END WHERE id IN ({$in})", $bindings);

        return count($changes);
    }

    /**
     * Every product, in chunks of CHUNK (by id): the nightly safety net. $onChunk(int $products, int $written) is called after each chunk.
     *
     * @return array{products: int, written: int}
     */
    public function refreshAll(?callable $onChunk = null): array
    {
        $last = 0;
        $products = 0;
        $written = 0;

        while (true) {
            $ids = DB::table('catalog_products')->where('id', '>', $last)->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $last = (int) end($ids);
            $rows = $this->refresh($ids);
            $products += count($ids);
            $written += $rows;
            $onChunk === null || $onChunk(count($ids), $rows);
        }

        return ['products' => $products, 'written' => $written];
    }

    /**
     * P6c: a product is available when at least one of its SHOWN variations has stock > 0 (the same stock reading as AvailablePriceRule); with none shown it is not.
     *
     * @param list<string> $variationIds the product's shown variations
     * @param array<string, int> $stock quantity by variation id (a missing id is 0)
     */
    public static function availableOf(array $variationIds, array $stock): bool
    {
        foreach ($variationIds as $variationId) {
            if (($stock[$variationId] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $variationIds the product's shown variations
     * @param array<string, \EasyCo\Pricing\Contracts\PriceQuote> $quotes
     * @param array<string, int> $stock
     */
    private function priceOf(array $variationIds, array $quotes, array $stock): ?int
    {
        $selected = AvailablePriceRule::select($variationIds, $quotes, $stock);

        if ($selected === []) {
            return null;
        }

        try {
            return PriceRange::fromQuotes($selected)->lowestFinalQuote()?->final->gross()->minorValue();
        } catch (InvalidArgumentException) {
            // mixed currencies / tax modes cannot be ranked: such a product has no sortable price (the card cannot show one either)
            return null;
        }
    }

    /**
     * @param list<string> $variationIds
     * @return array<string, int>
     */
    private function stock(array $variationIds): array
    {
        if ($variationIds === []) {
            return [];
        }

        $stock = [];

        foreach (StockLevelModel::query()->whereIn('variation_id', $variationIds)->get(['variation_id', 'quantity']) as $level) {
            $stock[(string) $level->variation_id] = (int) $level->quantity;
        }

        return $stock;
    }

    /**
     * variation id => PriceQuote, the SAME way the card reads it (CatalogReader::quotesByVariation): through the scope resolver and the range resolver.
     *
     * @param list<string> $variationIds
     * @return array<string, \EasyCo\Pricing\Contracts\PriceQuote>
     */
    private function quotes(array $variationIds): array
    {
        if ($variationIds === []) {
            return [];
        }

        $contexts = [];

        foreach (app(CatalogScopeResolver::class)->forVariations($variationIds) as $variationId => $data) {
            $contexts[] = new PriceContext(
                priceableId: (string) $variationId,
                quantity: 1,
                currency: DefaultCurrency::get()->code(),
                productId: $data['productId'],
                matchingScopeReferenceIds: $data['matchingScopeReferenceIds'],
            );
        }

        return $contexts === [] ? [] : app(PriceRangeResolver::class)->resolveQuotes($contexts);
    }
}
