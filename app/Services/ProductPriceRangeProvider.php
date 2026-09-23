<?php

namespace App\Services;

use EasyCo\Catalog\Enums\VariationStatus;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Contracts\PriceRangeResolver;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\PriceRange;

/**
 * Builds a PriceRange (EasyCo\Pricing\PriceRange) for one or many
 * Products at once — the app-layer composition point for the admin
 * product listing's "from X" display (a later, UI-only task; this class
 * is domain-core wiring, no UI change of its own).
 *
 * RULE, STATED ONCE HERE: a Product's range covers its NON-ARCHIVED
 * Variations only. A variation whose price cannot be resolved (no
 * "Regular Prices" item configured for it, per PriceRangeResolver's own
 * omission contract) is simply skipped, not an error — a Product where
 * NOTHING resolves yields PriceRange::fromQuotes([]) (isEmpty() ===
 * true), never an exception.
 *
 * DEFERRED, EXPLICITLY: the storefront will need the SAME provider
 * later, filtered further to only is_visible + isEffectivelyPurchasable()
 * variations (an admin merchant wants to see every non-archived variation's
 * price while editing; a customer must only ever see what they could
 * actually buy). No speculative filter parameter is added here ahead of
 * that real need — this class covers exactly the admin case today.
 *
 * WHY A RAW VariationModel QUERY, NOT A NEW VariationRepository METHOD:
 * finding "every non-archived variation id, grouped by product, for a
 * SET of products" has no existing batched Catalog repository method,
 * and adding one was outside this task's explicitly authorized batched-
 * read list (VariationRepository::findByIds(),
 * ProductRepository::findBrandIdsByProductIds(),
 * ProductCategoryRepository::findByProductIds(),
 * ProductTagRepository::findByProductIds() — see those methods' own
 * docblocks). A direct read against catalog_variations is the same
 * established precedent every other read-only, non-invariant-bearing
 * lookup in this codebase already uses (e.g. ProductResource's own
 * "every Resource's table/listing reads directly through its Eloquent
 * model" docblock, EditVariableProduct's hasDeclaredAxes()/
 * hasArchivedVariations()) — never a write, so it carries none of
 * CLAUDE.md's "admin UI must go through the domain layer" risk, which is
 * specifically about writes bypassing domain invariants.
 *
 * BOUNDED QUERY COUNT ACROSS PRODUCTS: this variation-id lookup is ONE
 * query regardless of how many productIds are given; the actual
 * Variation objects, scope data, and quotes are then all resolved via
 * the already-batched CatalogScopeResolver::forVariations() and
 * PriceRangeResolver::resolveQuotes() (ONE call each) — never one query
 * per product or per variation. Proven with a real query-count
 * measurement (5 vs. 25 products, equal counts) in this class's own
 * test.
 */
final class ProductPriceRangeProvider
{
    public function __construct(
        private readonly CatalogScopeResolver $catalogScopeResolver,
        private readonly PriceRangeResolver $priceRangeResolver,
    ) {
    }

    public function forProduct(string $productId): PriceRange
    {
        return $this->forProducts([$productId])[$productId] ?? PriceRange::fromQuotes([]);
    }

    /**
     * @param string[] $productIds
     * @return array<string, PriceRange> keyed by product id — every id
     *   given is present in the result, even one with an empty range.
     *   The provider never assumes any ordering of the returned quotes;
     *   determinism is PriceRange's own job (see its class docblock).
     */
    public function forProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $variationIdsByProductId = $this->nonArchivedVariationIdsByProductId($productIds);

        $allVariationIds = array_merge([], ...array_values($variationIdsByProductId));

        $quotesByVariationId = [];

        if ($allVariationIds !== []) {
            $scopeDataByVariationId = $this->catalogScopeResolver->forVariations($allVariationIds);

            $contexts = [];
            foreach ($scopeDataByVariationId as $variationId => $data) {
                $contexts[] = new PriceContext(
                    priceableId: $variationId,
                    quantity: 1,
                    currency: DefaultCurrency::get()->code(),
                    productId: $data['productId'],
                    matchingScopeReferenceIds: $data['matchingScopeReferenceIds'],
                );
            }

            $quotesByVariationId = $this->priceRangeResolver->resolveQuotes($contexts);
        }

        $result = [];
        foreach ($productIds as $productId) {
            $quotesForProduct = [];

            foreach ($variationIdsByProductId[$productId] ?? [] as $variationId) {
                if (isset($quotesByVariationId[$variationId])) {
                    $quotesForProduct[$variationId] = $quotesByVariationId[$variationId];
                }
            }

            $result[$productId] = PriceRange::fromQuotes($quotesForProduct);
        }

        return $result;
    }

    /**
     * @param string[] $productIds
     * @return array<string, string[]> non-archived variation ids, keyed by product id
     */
    private function nonArchivedVariationIdsByProductId(array $productIds): array
    {
        $grouped = [];

        foreach (VariationModel::whereIn('product_id', $productIds)
            ->where('status', '!=', VariationStatus::ARCHIVED->value)
            ->get(['id', 'product_id']) as $row) {
            $grouped[(string) $row->product_id][] = (string) $row->id;
        }

        return $grouped;
    }
}
