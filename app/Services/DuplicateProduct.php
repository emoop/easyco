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
use LogicException;
use RuntimeException;

/**
 * "Duplicate" — admin-panel-design.md §13.2. An app-layer orchestration
 * service, not a Product domain method: no real domain invariant is
 * being protected here, only field values copied into a new entity —
 * mirrors DetachProductFromCatalogLookup's own "app-layer
 * orchestration, not a Product method" precedent.
 *
 * SIMPLE PRODUCTS ONLY in this pass — §13.2's own text describes
 * copying VARIABLE axis declarations too, but that is explicitly this
 * task's own deferred scope (the VARIABLE creation wizard doesn't
 * exist yet to review/adjust a copied axis selection against). A
 * VARIABLE source throws rather than silently doing a partial copy —
 * the real, only guard against this is ProductResource's own
 * ->visible() check hiding the action entirely for a VARIABLE row, so
 * reaching this exception at all would mean that check was bypassed.
 *
 * TWO ASSUMPTIONS NOT EXPLICITLY CONFIRMED BY §13.2's OWN TEXT —
 * flagged per this task's own instruction, not silently guessed:
 *  1. §13.2 says nothing about the universal Variation's barcode/
 *     is_purchasable. NOT copied here: a duplicate's barcode would
 *     collide with the source's in real-world scanning use if copied
 *     verbatim, so the new universal Variation starts with barcode
 *     unset and is_purchasable at its own construction default (true).
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

            if ($source->type() !== ProductType::SIMPLE) {
                throw new LogicException(
                    "Product \"{$sourceProductId}\" is VARIABLE — DuplicateProduct only supports SIMPLE ".
                    'products in this pass (admin-panel-design.md §13.2\'s own deferred VARIABLE scope).'
                );
            }

            $newName = "{$source->name()} (".__('products.duplicate_suffix').')';
            $slug = Hook::apply('catalog.product.slug', '', $newName);
            $baseSku = Hook::apply('catalog.product.base_sku', '');

            // createSimple() itself already defaults status to DRAFT —
            // "always DRAFT regardless of the source's status" (§13.2)
            // is already true by construction, nothing further to call.
            $duplicate = Product::createSimple($newName, $baseSku, $slug);

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
