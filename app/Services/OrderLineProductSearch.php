<?php

namespace App\Services;

use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Enums\VariationStatus;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The order edit dialog's own "which product am I adding" search — the
 * picker behind order-editing-design.md §1/E2's first editable fact ("add a
 * product/variation with a quantity"), stage 4b-ii.
 *
 * ITS MATCHING IS PRODUCTRESOURCE'S OWN, READ FROM THE INSTALLED FRAMEWORK
 * RATHER THAN INVENTED: ProductResource's list already makes
 * catalog_products.name and catalog_products.base_sku searchable, and
 * Filament v5.8.1's real matching for a searchable column is (read
 * directly from vendor/filament/tables/src/Columns/Concerns/
 * InteractsWithTableQuery.php::applySearchConstraint()) ONE
 * `->where(<column>, 'like', "%{term}%")` per searchable column, with the
 * term passed through untouched and the comparator left to the column's
 * own collation — never a LOWER()/LIKE pair, and never a `%`/`_` escape.
 * On this stack (MySQL, utf8mb4_unicode_ci) that reads as a
 * case-insensitive fragment match. This class issues exactly that
 * constraint shape, over the same kind of columns a merchant names a
 * sellable thing by: the product's name AND base_sku (ProductResource's own
 * two searchable columns, matched identically), plus the variation's sku and
 * barcode — the two columns VariationRepository::findBySku()/findByBarcode()
 * already serve for POS scanning, searched here by fragment instead of by
 * whole value.
 *
 * WHAT IS EXCLUDED, AND WHY:
 *  - products whose own status is not ACTIVE (draft/archived) — the same
 *    status ProductResource's own list view defaults to (its `statusView`
 *    default is ACTIVE, i.e. `where('status', 'active')`);
 *  - variations whose status is not ACTIVE, and variations with
 *    is_purchasable = false — together exactly the two conditions
 *    Variation::isEffectivelyPurchasable() states, which is the SAME gate
 *    the storefront's own add-to-cart applies (CartController::store():
 *    `if ($variation === null || ! $variation->isEffectivelyPurchasable())`).
 *    A merchant's explicit "this cannot be sold" is never offered as
 *    something to add to an order.
 *  - catalog_visibility is deliberately NOT filtered: ProductResource's
 *    list does not filter it either (it is a separate TernaryFilter, and a
 *    hidden product still renders there with its own badge), and an order
 *    taken over the phone may legitimately contain a product that is
 *    hidden from the storefront.
 *  - a variation with no resolvable PRICE is NOT excluded here, honestly:
 *    price resolution is a Pricing read per variation (CatalogScopeResolver
 *    + PriceResolver + CostPriceProvider — see CheckoutLinePricer), which
 *    is not something to run once per row of a live-typing search. The
 *    dialog resolves the price for the ONE selected variation instead
 *    (shown before submitting), and a variation that genuinely has no
 *    price refuses the submission with its own translated message rather
 *    than being silently absent from the picker.
 *
 * NO MIGRATION, AND NO NEW INDEX — an honest report, not an omission: a
 * leading-`%` LIKE cannot use an index on any of these three columns
 * anyway (catalog_products.name is not indexed at all today, and the
 * unique indexes on catalog_variations.sku/barcode are unusable for a
 * fragment match). This is the same cost ProductResource's own list search
 * already pays over the same columns, so the picker adds no new class of
 * query to the application. A future full-text-index decision would be a
 * change to BOTH searches at once, never to this one alone.
 *
 * ONE ROW PER SELLABLE VARIATION, NOT PER PRODUCT (D1's choice (b)): a
 * SaleLine's priceableId references a specific VARIATION, and a variable
 * product has several, each with its own sku. Flattening the search to
 * variation level is what makes a single picker enough — the merchant
 * picks the exact thing they are adding, with no second cascading select
 * and no server-side guessing about which variation a product-level pick
 * meant. It is also the only shape that can honestly match a BARCODE,
 * which lives on the variation, not on the product.
 */
final class OrderLineProductSearch
{
    /**
     * D1's own cap for a live-typing search — a merchant naming a product
     * they have in hand needs a handful of candidates, never a listing.
     */
    public const RESULT_LIMIT = 20;

    /**
     * The facts a result must carry to name itself: the variation's own id
     * and sku, the PARENT product's name, and the PARENT's base_sku (see
     * labelsFrom() for how they compose).
     */
    private const COLUMNS = [
        'catalog_variations.id as variation_id',
        'catalog_variations.sku as variation_sku',
        'catalog_products.name as product_name',
        'catalog_products.base_sku as product_base_sku',
    ];

    /**
     * The dialog's Select options: variationId => label, at most $limit rows.
     *
     * @return array<string, string>
     */
    public function results(string $search, int $limit = self::RESULT_LIMIT): array
    {
        $term = trim($search);

        if ($term === '' || $limit < 1) {
            return [];
        }

        $like = '%'.$term.'%';

        $rows = $this->query()
            ->where(function (Builder $query) use ($like): void {
                // Filament's own shape, one clause per searchable column —
                // see this class's own docblock for the exact source read.
                $query->where('catalog_products.name', 'like', $like)
                    ->orWhere('catalog_products.base_sku', 'like', $like)
                    ->orWhere('catalog_variations.sku', 'like', $like)
                    ->orWhere('catalog_variations.barcode', 'like', $like);
            })
            ->orderBy('catalog_products.name')
            ->orderBy('catalog_variations.sort_order')
            ->orderBy('catalog_variations.id')
            ->limit($limit)
            ->get(self::COLUMNS);

        return $this->labelsFrom($rows);
    }

    /**
     * One variation's own label, for the Select's redisplayer: a value that
     * was selected before a submission that failed validation elsewhere on
     * the form must render its label again, never a bare id.
     *
     * DELIBERATELY NOT FILTERED BY SELLABILITY, unlike results(): this
     * answers "what is this id", not "what may be added". The two must not
     * be confused — a product archived while the dialog was open still
     * needs to NAME the value the merchant picked (Filament's own Select
     * would otherwise fail its in-options validation with a bare "The
     * selected product is invalid.", hiding the reason), and the refusal
     * for adding it belongs to OrderAddLinePricer, with its own translated
     * sentence.
     *
     * $variationId is declared string|int because a Select's own state can
     * legitimately come back either way — PHP turns a numeric string ARRAY
     * KEY into an int in results()/labelsFrom() below, and Filament's own
     * option state cast can hand the value over as an int too. Normalising
     * here keeps every caller from having to remember that.
     *
     * THE LABEL IS BUILT BY THE ONE BUILDER results() USES, axis values and
     * all (labelsFrom()): a value re-shown after a failed submission must
     * read exactly like the option the merchant picked, never a second
     * spelling of the same variation. That costs this path the axis query as
     * well — one extra read for ONE variation, on a render that already
     * reads the variation and its product.
     */
    public function label(string|int $variationId): ?string
    {
        $variationId = trim((string) $variationId);

        if ($variationId === '') {
            return null;
        }

        $row = VariationModel::query()
            // A LEFT join on purpose: a soft-deleted product must still not
            // stop its variation from being named. The parent's base_sku is
            // what the label leads with, and the variation's own sku is the
            // documented fallback for a parent that has none — so this row
            // must come back even when the product row is gone from the
            // application's own point of view.
            ->leftJoin('catalog_products', 'catalog_products.id', '=', 'catalog_variations.product_id')
            ->where('catalog_variations.id', $variationId)
            ->first(self::COLUMNS);

        if ($row === null) {
            return null;
        }

        return $this->labelsFrom([$row])[$variationId] ?? null;
    }

    /**
     * @return Builder<VariationModel>
     */
    private function query(): Builder
    {
        return VariationModel::query()
            ->join('catalog_products', 'catalog_products.id', '=', 'catalog_variations.product_id')
            // A raw join does NOT apply ProductModel's own SoftDeletes global
            // scope to the joined table, so the exclusion is written out —
            // the soft-delete safety net must not leak a deleted row through
            // a hand-written join. VariationModel's own scope DOES apply
            // automatically: that is the model being queried.
            ->whereNull('catalog_products.deleted_at')
            ->where('catalog_products.status', ProductStatus::ACTIVE->value)
            ->where('catalog_variations.status', VariationStatus::ACTIVE->value)
            ->where('catalog_variations.is_purchasable', true);
    }

    /**
     * One label per row: `{product name} ({sku}{ — axis values})` —
     * `Alpha Widget (SKU-A)` for a variation with no axis values,
     * `T-Shirt (TEE-1001 — Black, M)` for one that has them. The sku is the
     * PARENT product's base_sku, with an em dash (spaced) before the axis
     * values so the choice being made reads as its own fact.
     *
     * Fallbacks: a parent with no base_sku shows the variation's own sku, and
     * a row with no product name shows the sku part alone — never an empty
     * option, the same "never a blank label" posture lineProductHtml() takes
     * for a line with no product name.
     *
     * The axis values are read in ONE batched query for every row (see
     * attributesForMany()), never one query per row: this shapes a live-typing
     * search's whole result set, up to RESULT_LIMIT rows at a time.
     *
     * @param  iterable<object{variation_id: mixed, variation_sku: mixed, product_name: mixed, product_base_sku: mixed}>  $rows
     * @return array<string, string>
     */
    private function labelsFrom(iterable $rows): array
    {
        $identities = [];
        $variationIds = [];

        foreach ($rows as $row) {
            $variationId = (string) $row->variation_id;

            $identities[$variationId] = [
                'name' => trim((string) ($row->product_name ?? '')),
                'parent' => trim((string) ($row->product_base_sku ?? '')),
                'sku' => trim((string) ($row->variation_sku ?? '')),
            ];

            $variationIds[] = $variationId;
        }

        $attributeValues = $this->attributesForMany($variationIds);

        $labels = [];

        foreach ($identities as $variationId => $identity) {
            $sku = $identity['parent'] === '' ? $identity['sku'] : $identity['parent'];
            $axisValues = implode(', ', $attributeValues[(string) $variationId] ?? []);

            $inner = $axisValues === '' ? $sku : ($sku === '' ? $axisValues : "{$sku} — {$axisValues}");

            $labels[(string) $variationId] = match (true) {
                $identity['name'] === '' => $inner,
                $inner === '' => $identity['name'],
                default => "{$identity['name']} ({$inner})",
            };
        }

        return $labels;
    }

    /**
     * The variations' sold combinations' VALUES, as variation id => ordered
     * value list — the display half of §3.13's `sold_attributes`, for any
     * number of variations, in ONE query rather than one per variation.
     *
     * THE QUERY IS CatalogDeletion::attributesForMany()'S OWN, kept
     * deliberately in step with it: one `whereIn` over
     * catalog_variation_attribute_values joined to the definition and the
     * value, with the SAME ordering (`variation_id`, then
     * `catalog_attribute_definitions.id`). That ordering is the one
     * authoritative axis order this stack has — definitions are
     * auto-increment ids, so it is creation order, and it is the same order
     * SaleLineSnapshotBuilder writes into a line's own `sold_attributes` —
     * so a picker label and the receipt for the line it becomes list the
     * values the same way round.
     *
     * A variation with no attribute rows (a UNIVERSAL one) is simply absent;
     * every caller reads that as "no combination", which is what it means.
     *
     * @param  list<string>  $variationIds
     * @return array<string, list<string>>
     */
    private function attributesForMany(array $variationIds): array
    {
        if ($variationIds === []) {
            return [];
        }

        $rows = DB::table('catalog_variation_attribute_values as av')
            ->join('catalog_attribute_definitions as d', 'd.id', '=', 'av.attribute_definition_id')
            ->join('catalog_attribute_values as v', 'v.id', '=', 'av.attribute_value_id')
            ->whereIn('av.variation_id', $variationIds)
            ->orderBy('av.variation_id')
            ->orderBy('d.id')
            ->get(['av.variation_id', 'v.value as value']);

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) $row->variation_id][] = (string) $row->value;
        }

        return $grouped;
    }
}
