<?php

namespace App\Services;

use App\Enums\CatalogLookupKind;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductCategoryRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\ProductCategory;
use EasyCo\Catalog\ProductTag;

/**
 * The single place a product is detached from a Brand/Season/Category/
 * Tag/AttributeDefinition(descriptive)/AttributeValue(descriptive) —
 * used identically by both the inline (<=50 selected) and queued
 * (Bus::batch(), >50 selected) bulk-unlink paths on every drill-down
 * page (admin-panel-design.md §7's own batching threshold). Every
 * detach*() method is idempotent: detaching an already-unlinked
 * product returns false (no-op), never throws — mirrors the existing
 * bulk category/tag add pattern's own idempotency posture. Returns
 * true only when a real change was made, so callers can report an
 * accurate "N of M actually detached" count.
 */
final class DetachProductFromCatalogLookup
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductCategoryRepository $productCategories,
        private readonly ProductTagRepository $productTags,
        private readonly AttributeDefinitionRepository $attributeDefinitions,
        private readonly AttributeValueRepository $attributeValues,
    ) {
    }

    public function detach(CatalogLookupKind $kind, string $productId, ?string $entityId): bool
    {
        return match ($kind) {
            CatalogLookupKind::BRAND => $this->detachBrand($productId),
            CatalogLookupKind::SEASON => $this->detachSeason($productId),
            CatalogLookupKind::CATEGORY => $this->detachCategory($productId, $entityId),
            CatalogLookupKind::TAG => $this->detachTag($productId, $entityId),
            CatalogLookupKind::ATTRIBUTE_DEFINITION => $this->detachAttributeDefinition($productId, $entityId),
            CatalogLookupKind::ATTRIBUTE_VALUE => $this->detachAttributeValue($productId, $entityId),
            CatalogLookupKind::PRODUCT_GROUP => $this->detachProductGroup($productId),
        };
    }

    private function detachBrand(string $productId): bool
    {
        $product = $this->products->findById($productId);

        if ($product === null || $product->brandId() === null) {
            return false;
        }

        $product->assignBrand(null);
        $this->products->save($product);

        return true;
    }

    private function detachSeason(string $productId): bool
    {
        $product = $this->products->findById($productId);

        if ($product === null || $product->seasonId() === null) {
            return false;
        }

        $product->assignSeason(null);
        $this->products->save($product);

        return true;
    }

    private function detachProductGroup(string $productId): bool
    {
        $product = $this->products->findById($productId);

        if ($product === null || $product->productGroupId() === null) {
            return false;
        }

        $product->assignProductGroup(null);
        $this->products->save($product);

        return true;
    }

    private function detachCategory(string $productId, string $categoryId): bool
    {
        $pivot = $this->findProductCategoryPivot($productId, $categoryId);

        if ($pivot === null) {
            return false;
        }

        $this->productCategories->remove((string) $pivot->id());

        return true;
    }

    private function detachTag(string $productId, string $tagId): bool
    {
        $pivot = $this->findProductTagPivot($productId, $tagId);

        if ($pivot === null) {
            return false;
        }

        $this->productTags->remove((string) $pivot->id());

        return true;
    }

    /**
     * Descriptive scope only — the caller (each bulk-unlink action) is
     * responsible for only ever offering this on the descriptive
     * drill-down, never the axis one. As a genuine server-side
     * backstop, not just a UI hint: Product::removeDescriptiveAttribute()
     * itself still throws CannotRemoveVariationAxisAttributeException
     * if $definitionId turns out to actually be declared as an axis on
     * this product, and that exception is deliberately left to
     * propagate uncaught here rather than silently swallowed into a
     * false "detached" result — axis usage is never bulk-unlinkable
     * through this mechanism, full stop.
     */
    private function detachAttributeDefinition(string $productId, string $definitionId): bool
    {
        $product = $this->products->findById($productId);
        $definition = $this->attributeDefinitions->findById($definitionId);

        if ($product === null || $definition === null || ! array_key_exists($definitionId, $product->descriptiveAttributes())) {
            return false;
        }

        $product->removeDescriptiveAttribute($definition);
        $this->products->save($product);

        return true;
    }

    /** Same "descriptive scope only, real server-side backstop" posture as detachAttributeDefinition() above. */
    private function detachAttributeValue(string $productId, string $valueId): bool
    {
        $product = $this->products->findById($productId);
        $value = $this->attributeValues->findById($valueId);

        if ($product === null || $value === null) {
            return false;
        }

        $definitionId = $value->attributeDefinitionId();
        $current = $product->descriptiveAttributes()[$definitionId] ?? null;

        if (! ($current instanceof AttributeValue) || $current->id() !== $valueId) {
            return false;
        }

        $definition = $this->attributeDefinitions->findById($definitionId);

        if ($definition === null) {
            return false;
        }

        $product->removeDescriptiveAttribute($definition);
        $this->products->save($product);

        return true;
    }

    private function findProductCategoryPivot(string $productId, string $categoryId): ?ProductCategory
    {
        foreach ($this->productCategories->findByProductId($productId) as $pivot) {
            if ($pivot->categoryId() === $categoryId) {
                return $pivot;
            }
        }

        return null;
    }

    private function findProductTagPivot(string $productId, string $tagId): ?ProductTag
    {
        foreach ($this->productTags->findByProductId($productId) as $pivot) {
            if ($pivot->tagId() === $tagId) {
                return $pivot;
            }
        }

        return null;
    }
}
