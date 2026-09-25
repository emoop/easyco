<?php

namespace App\Sandbox;

use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Enums\VariationStatus;
use EasyCo\Catalog\Enums\VariationType;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Enums\ProcessingStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * THE SANDBOX'S ONE READ RULE — Prompt D, D3: "a product is listed if
 * status = ACTIVE and catalog visibility is VISIBLE. A variation is shown
 * if status = ACTIVE and is_visible ... Implement this rule in ONE place
 * (a small read class under App\Sandbox\), documented as provisional: the
 * real storefront will own this rule later — flag it, do not promote it
 * to a shared service."
 *
 * THE VARIATION HALF OF THAT RULE HAS TWO BRANCHES, and the second one is
 * deliberately NOT "ACTIVE + is_visible". D3's own wording ("a variation
 * is shown if status = ACTIVE and is_visible") only fits a VARIABLE
 * product; applying it to a SIMPLE product hides every SIMPLE product's
 * only variation, and that is not a data accident but the domain's own
 * design — so the rule is stated per product type instead of guessed:
 * - VARIABLE product -> its STANDARD variations with status = ACTIVE and
 *   is_visible = true (listedVariationModels()).
 * - SIMPLE product -> its single UNIVERSAL variation, shown on
 *   status = ACTIVE ALONE (universalVariationModel()). Variation's own
 *   constructor forces isVisible = false for a UNIVERSAL variation and
 *   Variation::setVisible(true) throws for one ("The Universal variation
 *   can never be made customer-visible"), because that variation IS the
 *   product: there is nothing for a customer to select between. Its
 *   stock and purchasable state are therefore rendered under the price,
 *   never as a one-row table — see SandboxUniversalVariation.
 *
 * PROVISIONAL, AND DELIBERATELY NOT IN app/Services/ OR ANY PACKAGE:
 * this is one guess at what "a customer may see this" means, made so a
 * preview page can exist before storefront-frontend-design.md's real
 * pages are built. It is NOT a Catalog invariant: nothing in the Catalog
 * domain prevents a HIDDEN product from existing or being ordered
 * through the API, and Catalog itself has no "may a customer see this"
 * concept to reuse. When the real storefront is built, its own rule
 * (storefront-frontend-design.md §7's category/tag paths, and whatever
 * visibility means there) replaces this class wholesale — deleting
 * App\Sandbox\ must never break anything else (D2's removability
 * requirement), which is exactly why this rule lives in the sandbox's own
 * folder rather than being promoted to a shared service whose name would
 * imply a domain decision that has not been made.
 *
 * WHY RAW ELOQUENT READS ARE CORRECT HERE, NOT A SHORTCUT: both are
 * READS, and this project's own established posture (ProductResource's
 * "every Resource's table/listing reads directly through its Eloquent
 * model" docblock; OrderAdminReader's raw reads) is that a read-only
 * listing carrying no invariant may query the model directly — CLAUDE.md's
 * "UI code must go through the domain layer" rule is specifically about
 * WRITES bypassing domain invariants. Domain objects are still loaded
 * wherever domain BEHAVIOR is needed: SandboxProductPage loads real
 * Variations through VariationRepository, because
 * isEffectivelyPurchasable() is a Variation method, not a column.
 *
 * QUERY-COUNT CONTRACT (D4): paginateListedProducts() is the ONE place
 * that reads the list's product rows, and it reads them in a fixed number
 * of queries no matter how many rows the page shows — the page's own
 * query + the paginator's count query + one eager-loaded brand read + the
 * correlated first-image subquery riding along inside the page's own
 * query. Per-row work (prices) happens in SandboxProductListPage through
 * ONE batched ProductPriceRangeProvider call, never per row — proven by
 * SandboxProductListQueryCountTest with real, printed numbers (5 vs. 24
 * products).
 */
final class SandboxCatalogReader
{
    /** D4's own number, stated once, here. */
    public const PRODUCTS_PER_PAGE = 24;

    /**
     * The listed products, newest first by the product timeline —
     * `timeline_at desc, id desc`, the exact ordering
     * catalog_products_timeline_id_index exists for (see that migration's
     * own docblock) and the same default the admin's own ListProducts
     * table uses. The id tie-break is not decoration: two products
     * created in the same second must still have one stable order, or
     * pagination could repeat or drop rows between pages.
     *
     * @return LengthAwarePaginator<int, ProductModel>
     */
    public function paginateListedProducts(int $perPage = self::PRODUCTS_PER_PAGE): LengthAwarePaginator
    {
        return $this->listedProductsQuery()
            ->with('brand:id,name')
            ->addSelect(['thumbnail_path' => self::firstImagePathSubquery()])
            ->paginate($perPage);
    }

    /** A listed product, by id — null (a 404, never a page) for anything the list itself would not show. */
    public function findListedProduct(string $productId): ?ProductModel
    {
        return $this->listedProductsQuery()
            ->with('brand:id,name')
            ->whereKey($productId)
            ->first();
    }

    /**
     * D3's VARIABLE branch: the STANDARD variations a VARIABLE product
     * shows — status = ACTIVE and is_visible. (A SIMPLE product's rule is
     * universalVariationModel() below; the two branches are stated in
     * this class's own docblock, not duplicated here.) Ordered by id —
     * the codebase's own deterministic fallback (Variation::sort_order is
     * deliberately not exposed by the domain, see VariationRepository's
     * docblock, and PriceRange's own docblock states the same "never rely
     * on the caller's iteration order" posture), so the variation table
     * renders identically on every request.
     *
     * @return array<int, VariationModel>
     */
    public function listedVariationModels(string $productId): array
    {
        return VariationModel::query()
            ->where('product_id', $productId)
            ->where('status', VariationStatus::ACTIVE->value)
            ->where('is_visible', true)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * D3's SIMPLE branch: a SIMPLE product's single UNIVERSAL variation,
     * shown on status = ACTIVE ALONE.
     *
     * is_visible is deliberately NOT part of this query. The domain forces
     * isVisible = false for a UNIVERSAL variation (Variation's own
     * constructor) and Variation::setVisible(true) throws for one, so
     * "ACTIVE + is_visible" would hide the only variation every SIMPLE
     * product has — the one a customer actually prices and buys. Its
     * status alone answers "may a customer see this" for this branch; see
     * this class's own docblock for the full reasoning.
     *
     * Exactly one row exists by construction (Product::createSimple()
     * creates the UNIVERSAL variation with the product, and it is never
     * deleted — only archived alongside the product). orderBy('id') plus
     * first() keeps a hand-edited database deterministic instead of
     * picking an arbitrary row.
     */
    public function universalVariationModel(string $productId): ?VariationModel
    {
        return VariationModel::query()
            ->where('product_id', $productId)
            ->where('type', VariationType::UNIVERSAL->value)
            ->where('status', VariationStatus::ACTIVE->value)
            ->orderBy('id')
            ->first();
    }

    /**
     * D3's product half, in ONE place — every listed-product query in
     * this class goes through here, so "ACTIVE + VISIBLE" exists exactly
     * once in the sandbox instead of once per call site.
     */
    private function listedProductsQuery(): Builder
    {
        return ProductModel::query()
            ->where('status', ProductStatus::ACTIVE->value)
            ->where('catalog_visibility', CatalogVisibility::VISIBLE->value)
            ->orderByDesc('timeline_at')
            ->orderByDesc('id');
    }

    /**
     * The list's first image, as one correlated subquery INSIDE the
     * page's own query — the same shape ProductResource::table()'s own
     * thumbnail_path subquery already uses, and the reason D4's
     * query-count requirement holds: one query for the whole page
     * regardless of rows, never a media read per row.
     *
     * DELIBERATELY DIFFERENT FROM THE ADMIN'S OWN SUBQUERY IN TWO WAYS,
     * both customer-facing concerns the admin does not have:
     * - `type = MediaType::IMAGE` — the admin shows whatever the first
     *   attachment is, a VIDEO included (its own docblock says so); a
     *   customer product tile must never be a video file.
     * - `processing_status = ProcessingStatus::READY` — a failed/pending
     *   asset has no
     *   usable file on disk yet, so the sandbox would render a broken
     *   image. The real dev database contains both cases right now
     *   (confirmed by a read-only query: 10032 image/ready, 3
     *   image/failed, 1 video/ready), so this is not hypothetical.
     *
     * The id tie-break on catalog_media.id mirrors the product ordering's
     * own reasoning: two attachments with the same sort_order must still
     * resolve to one stable image across requests.
     *
     * A Query\Builder (a raw DB::table() query), NOT an Eloquent Builder —
     * this returns exactly what the admin's own thumbnail_path subquery
     * returns, and ->addSelect() accepts it as a subquery value.
     */
    private static function firstImagePathSubquery(): QueryBuilder
    {
        return DB::table('catalog_product_media')
            ->join('catalog_media', 'catalog_media.id', '=', 'catalog_product_media.media_id')
            ->whereColumn('catalog_product_media.product_id', 'catalog_products.id')
            ->where('catalog_media.type', MediaType::IMAGE->value)
            ->where('catalog_media.processing_status', ProcessingStatus::READY->value)
            ->orderBy('catalog_product_media.sort_order')
            ->orderBy('catalog_media.id')
            ->limit(1)
            ->select('catalog_media.path');
    }
}
