<?php

namespace App\Services;

use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\ProductCategoryRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\Enums\ProductType;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\ProductCategory;
use EasyCo\Catalog\ProductTag;
use EasyCo\Extensibility\Hook;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "Duplicate" — admin-panel-design.md §13.2, extended to VARIABLE per
 * product-duplication-and-templates-note.md's own domain-owner
 * decision. An app-layer orchestration service, not a Product domain
 * method: no real domain invariant is being protected here, only field
 * values copied into a new entity — mirrors
 * DetachProductFromCatalogLookup's own "app-layer orchestration, not a
 * Product method" precedent.
 *
 * SIMPLE AND VARIABLE BOTH SUPPORTED — but for VARIABLE, axis
 * declarations and variations are NEVER copied, full stop, not merely
 * deferred. This is a permanent domain-owner decision (see the note's
 * own "Confirmed by the domain owner" section for variations; axes
 * were later settled the same way): a VARIABLE duplicate is always
 * created via Product::createVariable() with zero declared axes — the
 * merchant declares them fresh on the Axes tab, which the existing
 * "Generate missing variations" mechanism already makes fast. Nothing
 * in this class ever touches VariationAxis or Variation for either
 * product type; the field-copying below (name/brand/season/product
 * group/categories/tags/description/descriptive attributes) is already
 * entirely type-agnostic.
 *
 * TWO ASSUMPTIONS NOT EXPLICITLY CONFIRMED BY §13.2's OWN TEXT —
 * flagged per this task's own instruction, not silently guessed:
 *  1. §13.2 says nothing about the universal Variation's barcode/
 *     is_purchasable. NOT copied here: a duplicate's barcode would
 *     collide with the source's in real-world scanning use if copied
 *     verbatim, so the new universal Variation starts with barcode
 *     unset and is_purchasable at its own construction default (true).
 *     (Moot for a VARIABLE source — it has no universal Variation.)
 *  2. §13.2 does explicitly confirm description/descriptive attributes
 *     are copied ("Assumed, not explicitly confirmed... easy to
 *     reverse") — implemented as copied, per that note.
 */
final class DuplicateProduct
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductCategoryRepository $productCategories,
        private readonly ProductTagRepository $productTags,
        private readonly AttributeDefinitionRepository $attributeDefinitions,
    ) {}

    public function duplicate(string $sourceProductId): Product
    {
        return DB::transaction(function () use ($sourceProductId): Product {
            $source = $this->products->findById($sourceProductId);

            if ($source === null) {
                throw new RuntimeException("Product \"{$sourceProductId}\" could not be found to duplicate.");
            }

            $newName = "{$source->name()} (".__('products.duplicate_suffix').')';
            $slug = Hook::apply('catalog.product.slug', '', $newName);
            $baseSku = Hook::apply('catalog.product.base_sku', '');

            // Both factories default status to DRAFT — "always DRAFT
            // regardless of the source's status" (§13.2) is already
            // true by construction, nothing further to call. Neither
            // factory declares any axis or variation — exactly the
            // "zero axes, merchant starts fresh" rule above.
            $duplicate = $source->type() === ProductType::SIMPLE
                ? Product::createSimple($newName, $baseSku, $slug)
                : Product::createVariable($newName, $baseSku, $slug);

            $duplicate->setCatalogVisibility($source->catalogVisibility());
            $duplicate->assignBrand($source->brandId());
            $duplicate->assignSeason($source->seasonId());
            $duplicate->assignProductGroup($source->productGroupId());
            $duplicate->changeDescription($source->description());

            foreach ($source->descriptiveAttributes() as $definitionId => $value) {
                $definition = $this->attributeDefinitions->findById((string) $definitionId);

                if ($definition === null) {
                    continue;
                }

                $duplicate->setDescriptiveAttribute($definition, $value);
            }

            $this->products->save($duplicate);

            $duplicateId = $duplicate->id();

            foreach ($this->productCategories->findByProductId($sourceProductId) as $pivot) {
                $this->productCategories->save(
                    new ProductCategory(id: null, productId: $duplicateId, categoryId: $pivot->categoryId())
                );
            }

            foreach ($this->productTags->findByProductId($sourceProductId) as $pivot) {
                $this->productTags->save(
                    new ProductTag(id: null, productId: $duplicateId, tagId: $pivot->tagId())
                );
            }

            return $duplicate;
        });
    }
}
