<?php

namespace App\Storefront\Visibility;

use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Enums\ProductType;
use EasyCo\Catalog\Enums\VariationStatus;
use EasyCo\Catalog\Enums\VariationType;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * THE ONE "what may a customer see" rule (storefront-design.md §2.2). It replaces, for the storefront, the provisional
 * rule of App\Sandbox\SandboxCatalogReader (which keeps working untouched).
 *
 * Every rule is written ONCE as a constant and used by BOTH faces: query scopes (for lists) and predicates (for a
 * loaded row), so the two cannot disagree. A test compares them on a mixed fixture.
 *
 *  - PRODUCT shown:    status = active AND catalog_visibility = visible AND not soft-deleted.
 *  - VARIATION shown:  VARIABLE product -> type = standard AND status = active AND is_visible = 1;
 *                      SIMPLE product   -> its UNIVERSAL variation with status = active (is_visible is false by
 *                      construction for a universal variation, so it is NOT part of this branch). A soft-deleted
 *                      variation is never shown.
 *  - CATEGORY shown:   at least one visible product in itself or any descendant (CategoryIndex computes it once).
 *  - BRAND / TAG shown: at least one visible product.
 *  - "in stock" is a FACT about a shown variation (stock_levels.quantity > 0), NEVER part of visibility.
 *
 * Whether a shown VARIATION also needs its PRODUCT to be visible is the caller's business: variations are always
 * read through visible products.
 */
final class StorefrontVisibility
{
    public const PRODUCT_STATUS = ProductStatus::ACTIVE;

    public const PRODUCT_VISIBILITY = CatalogVisibility::VISIBLE;

    public const VARIATION_STATUS = VariationStatus::ACTIVE;

    // ---------------------------------------------------------------- products

    /**
     * Restrict a products query (Eloquent or a raw query on the products table) to visible products.
     *
     * @template TQuery of EloquentBuilder|QueryBuilder
     *
     * @param TQuery $query
     * @return TQuery
     */
    public function products(EloquentBuilder|QueryBuilder $query, string $table = 'catalog_products'): EloquentBuilder|QueryBuilder
    {
        return $query
            ->where($table.'.status', self::PRODUCT_STATUS->value)
            ->where($table.'.catalog_visibility', self::PRODUCT_VISIBILITY->value)
            ->whereNull($table.'.deleted_at');
    }

    /** The predicate twin of products(): is this LOADED row visible? */
    public function isProductVisible(ProductModel $product): bool
    {
        return $product->status === self::PRODUCT_STATUS->value
            && $product->catalog_visibility === self::PRODUCT_VISIBILITY->value
            && $product->deleted_at === null;
    }

    // -------------------------------------------------------------- variations

    /**
     * Restrict a variations query (Eloquent or raw, on `catalog_variations`) to SHOWN variations (the two branches).
     *
     * @template TQuery of EloquentBuilder|QueryBuilder
     *
     * @param TQuery $query
     * @return TQuery
     */
    public function variations(EloquentBuilder|QueryBuilder $query, string $table = 'catalog_variations'): EloquentBuilder|QueryBuilder
    {
        $productIdsOfType = static fn (ProductType $type) => static fn ($sub) => $sub
            ->select('id')
            ->from('catalog_products')
            ->where('type', $type->value);

        return $query
            ->whereNull($table.'.deleted_at')
            ->where($table.'.status', self::VARIATION_STATUS->value)
            ->where(function ($shown) use ($table, $productIdsOfType): void {
                $shown->where(function ($variable) use ($table, $productIdsOfType): void {
                    $variable->where($table.'.type', VariationType::STANDARD->value)
                        ->where($table.'.is_visible', true)
                        ->whereIn($table.'.product_id', $productIdsOfType(ProductType::VARIABLE));
                })->orWhere(function ($simple) use ($table, $productIdsOfType): void {
                    $simple->where($table.'.type', VariationType::UNIVERSAL->value)
                        ->whereIn($table.'.product_id', $productIdsOfType(ProductType::SIMPLE));
                });
            });
    }

    /** The predicate twin of variations(): is this LOADED variation shown, given its product's type? */
    public function isVariationShown(VariationModel $variation, string $productType): bool
    {
        if ($variation->deleted_at !== null || $variation->status !== self::VARIATION_STATUS->value) {
            return false;
        }

        return match ($productType) {
            ProductType::VARIABLE->value => $variation->type === VariationType::STANDARD->value && (bool) $variation->is_visible,
            ProductType::SIMPLE->value => $variation->type === VariationType::UNIVERSAL->value,
            default => false,
        };
    }

    // ------------------------------------------------------ brands, tags, categories

    /** Brands with at least one visible product. */
    public function brands(EloquentBuilder|QueryBuilder $query, string $table = 'catalog_brands'): EloquentBuilder|QueryBuilder
    {
        return $query->whereExists(fn ($sub) => $this->products(
            $sub->selectRaw('1')->from('catalog_products')->whereColumn('catalog_products.brand_id', $table.'.id')
        ));
    }

    /** Tags with at least one visible product. */
    public function tags(EloquentBuilder|QueryBuilder $query, string $table = 'catalog_tags'): EloquentBuilder|QueryBuilder
    {
        return $query->whereExists(fn ($sub) => $this->products(
            $sub->selectRaw('1')
                ->from('catalog_products')
                ->join('catalog_product_tags', 'catalog_product_tags.product_id', '=', 'catalog_products.id')
                ->whereColumn('catalog_product_tags.tag_id', $table.'.id')
        ));
    }

    /**
     * (category_id, product_id) for every VISIBLE product: the ONE read a category's "shown" state and counts are
     * computed from (CategoryIndex runs it once per request).
     */
    public function visibleCategoryPairs(): QueryBuilder
    {
        return $this->products(
            \Illuminate\Support\Facades\DB::table('catalog_product_categories')
                ->join('catalog_products', 'catalog_products.id', '=', 'catalog_product_categories.product_id')
                ->select('catalog_product_categories.category_id', 'catalog_product_categories.product_id')
        );
    }

    /** The category rule: shown when its subtree holds at least one visible product. */
    public function isCategoryShown(int $visibleProductsInSubtree): bool
    {
        return $visibleProductsInSubtree >= 1;
    }
}
