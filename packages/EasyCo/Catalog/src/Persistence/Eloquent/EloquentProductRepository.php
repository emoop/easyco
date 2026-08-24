<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Enums\ProductType;
use EasyCo\Catalog\Enums\VariationStatus;
use EasyCo\Catalog\Enums\VariationType;
use EasyCo\Catalog\Exceptions\DuplicateVariationCombinationException;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Variation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Maps the Product aggregate (and all of its Variations) onto
 * catalog_products / catalog_variations / catalog_variation_attribute_values
 * via the Eloquent models in this namespace.
 *
 * Rehydrating a Product with its pre-existing Variations goes through
 * Product::reconstituteFromStorage() / Variation::reconstituteFromStorage()
 * — explicit, documented persistence-layer factory methods on the domain
 * classes themselves, not reflection. See those methods' docblocks for
 * exactly what they trust vs. what they still verify.
 */
final class EloquentProductRepository implements ProductRepository
{
    public function save(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $productModel = $product->id() !== null
                ? ProductModel::findOrFail($product->id())
                : new ProductModel();

            $productModel->type = $product->type()->value;
            $productModel->name = $product->name();
            $productModel->base_sku = $product->baseSku();
            $productModel->status = $product->status()->value;
            $productModel->catalog_visibility = $product->catalogVisibility()->value;

            if (! $productModel->exists) {
                // slug is a persistence-layer concern the domain Product
                // does not model at all (see catalog-domain-design.md §6,
                // "Eloquent model classes ... deferred"). Derived once at
                // creation time only — never overwritten on update, so an
                // existing product's URL never silently changes underneath
                // it just because save() was called again.
                $productModel->slug = Str::slug($product->name()).'-'.Str::lower(Str::random(6));
            }

            $productModel->save();

            if ($product->id() === null) {
                $product->assignId((string) $productModel->id);
            }

            foreach ($product->variations() as $variation) {
                $this->saveVariation($productModel, $product, $variation);
            }
        });
    }

    public function findById(string $id): ?Product
    {
        $model = ProductModel::find($id);

        return $model !== null ? $this->toDomainProduct($model) : null;
    }

    public function findByIdWithVariations(string $id): ?Product
    {
        $model = ProductModel::with('variations')->find($id);

        if ($model === null) {
            return null;
        }

        return $this->toDomainProduct($model, $model->variations);
    }

    public function findBySku(string $sku): ?Product
    {
        $variationModel = VariationModel::where('sku', $sku)->first();

        return $variationModel !== null
            ? $this->findByIdWithVariations((string) $variationModel->product_id)
            : null;
    }

    public function findByBarcode(string $barcode): ?Product
    {
        $variationModel = VariationModel::where('barcode', $barcode)->first();

        return $variationModel !== null
            ? $this->findByIdWithVariations((string) $variationModel->product_id)
            : null;
    }

    public function findByBaseSku(string $baseSku): ?Product
    {
        $model = ProductModel::where('base_sku', $baseSku)->first();

        return $model !== null
            ? $this->findByIdWithVariations((string) $model->id)
            : null;
    }

    private function saveVariation(ProductModel $productModel, Product $product, Variation $variation): void
    {
        $variationModel = $variation->id() !== null
            ? VariationModel::findOrFail($variation->id())
            : new VariationModel();

        $variationModel->product_id = $productModel->id;
        $variationModel->type = $variation->type()->value;
        $variationModel->status = $variation->status()->value;
        $variationModel->attribute_signature = $variation->attributeSignature()->value();
        $variationModel->sku = $variation->sku();
        $variationModel->barcode = $variation->barcode();
        $variationModel->is_visible = $variation->isVisible();
        $variationModel->is_purchasable = $variation->isPurchasable();
        $variationModel->short_description = $variation->shortDescription();
        $variationModel->shipping_class = $variation->shippingClass();
        $variationModel->weight_grams = $variation->weightGrams();
        $variationModel->length_mm = $variation->lengthMm();
        $variationModel->width_mm = $variation->widthMm();
        $variationModel->height_mm = $variation->heightMm();

        try {
            $variationModel->save();
        } catch (QueryException $e) {
            if ($this->isVariationSignatureUniqueViolation($e)) {
                throw DuplicateVariationCombinationException::fromDatabaseConstraintViolation(
                    $product->id() ?? '(unsaved)',
                    $e
                );
            }

            throw $e;
        }

        if ($variation->id() === null) {
            $variation->assignId((string) $variationModel->id);
            $product->reindexVariationAfterPersist($variation);
        }

        $this->syncVariationAttributeAssignments($variationModel, $variation);
    }

    /**
     * Detects a violation of catalog_variations_product_signature_unique —
     * the UNIQUE(product_id, attribute_signature) index from
     * 2026_08_23_000006_create_catalog_variations_table.php, the actual
     * race-condition-safe guarantee behind
     * DuplicateVariationCombinationException.
     *
     * The primary check is the driver-reported SQLSTATE (23000, integrity
     * constraint violation) and MySQL error code (1062, ER_DUP_ENTRY) from
     * $e->errorInfo — never $e->getMessage() string matching, which is
     * fragile against driver/locale/version differences. errorInfo[2] (the
     * driver's own error text, not the exception's formatted message) is
     * used only as a secondary, best-effort narrowing to this specific
     * index, so a 1062 on some other unique column doesn't get
     * misreported as a duplicate variation combination.
     */
    private function isVariationSignatureUniqueViolation(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];
        $sqlState = $errorInfo[0] ?? null;
        $driverErrorCode = $errorInfo[1] ?? null;

        if ($sqlState !== '23000' || (int) $driverErrorCode !== 1062) {
            return false;
        }

        $driverErrorMessage = (string) ($errorInfo[2] ?? '');

        return str_contains($driverErrorMessage, 'catalog_variations_product_signature_unique');
    }

    private function syncVariationAttributeAssignments(VariationModel $variationModel, Variation $variation): void
    {
        DB::table('catalog_variation_attribute_values')
            ->where('variation_id', $variationModel->id)
            ->delete();

        $assignments = $variation->attributeAssignments();

        if ($assignments === []) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ($assignments as $attributeDefinitionId => $attributeValueId) {
            $rows[] = [
                'variation_id' => $variationModel->id,
                'attribute_definition_id' => $attributeDefinitionId,
                'attribute_value_id' => $attributeValueId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('catalog_variation_attribute_values')->insert($rows);
    }

    /** @param iterable<VariationModel>|null $variationModels */
    private function toDomainProduct(ProductModel $model, ?iterable $variationModels = null): Product
    {
        $variations = [];

        if ($variationModels !== null) {
            $variationModels = $variationModels instanceof \Traversable
                ? iterator_to_array($variationModels)
                : $variationModels;

            $assignmentsByVariationId = $this->loadAttributeAssignments(
                array_map(static fn (VariationModel $m): int => $m->id, $variationModels)
            );

            foreach ($variationModels as $variationModel) {
                $variations[] = $this->toDomainVariation(
                    $variationModel,
                    $assignmentsByVariationId[$variationModel->id] ?? []
                );
            }
        }

        return Product::reconstituteFromStorage(
            id: (string) $model->id,
            name: $model->name,
            type: ProductType::from($model->type),
            baseSku: $model->base_sku,
            status: ProductStatus::from($model->status),
            catalogVisibility: CatalogVisibility::from($model->catalog_visibility),
            variations: $variations,
        );
    }

    private function toDomainVariation(VariationModel $model, array $attributeAssignments): Variation
    {
        return Variation::reconstituteFromStorage(
            id: (string) $model->id,
            productId: (string) $model->product_id,
            type: VariationType::from($model->type),
            status: VariationStatus::from($model->status),
            attributeAssignments: $attributeAssignments,
            // Variation now requires a non-empty string sku; catalog_variations.sku
            // itself is still a nullable column (not tightened by this vertical
            // slice — see vertical-slice-notes.md). Casting rather than passing
            // $model->sku directly means a legacy null-sku row surfaces as
            // Variation's own clear InvalidArgumentException instead of a bare
            // TypeError.
            sku: (string) $model->sku,
            barcode: $model->barcode,
            isVisible: (bool) $model->is_visible,
            isPurchasable: (bool) $model->is_purchasable,
            shortDescription: $model->short_description,
            shippingClass: $model->shipping_class,
            weightGrams: $model->weight_grams,
            lengthMm: $model->length_mm,
            widthMm: $model->width_mm,
            heightMm: $model->height_mm,
        );
    }

    /**
     * @param int[] $variationIds
     * @return array<int, array<int, int>> variation_id => [attribute_definition_id => attribute_value_id]
     */
    private function loadAttributeAssignments(array $variationIds): array
    {
        if ($variationIds === []) {
            return [];
        }

        $rows = DB::table('catalog_variation_attribute_values')
            ->whereIn('variation_id', $variationIds)
            ->get(['variation_id', 'attribute_definition_id', 'attribute_value_id']);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->variation_id][$row->attribute_definition_id] = $row->attribute_value_id;
        }

        return $grouped;
    }
}
