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
use RuntimeException;

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

            $this->saveProductModelWithSlugCollisionRetry($productModel, $product);

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

    public function findBySlug(string $slug): ?Product
    {
        $model = ProductModel::where('slug', $slug)->first();

        return $model !== null
            ? $this->findByIdWithVariations((string) $model->id)
            : null;
    }

    /**
     * Persists $productModel, retrying up to 3 times on a slug UNIQUE
     * constraint collision by appending an incrementing numeric suffix to
     * the slug (e.g. "червена-рокля" -> "червена-рокля-1" -> "-2" ->
     * "-3") before giving up and throwing. This is the authoritative,
     * race-condition-safe safety net against a slug collision — any
     * app-layer dedup check (e.g. in a slug-generator hook listener, see
     * App\Providers\CatalogSlugGeneratorServiceProvider) is best-effort
     * only, since a concurrent request could still claim the same slug
     * between that check and this insert/update.
     *
     * On success with an appended suffix, updates $product's own slug via
     * changeSlug() so the in-memory aggregate reflects exactly what was
     * actually persisted — a caller must never be left holding a Product
     * whose slug() disagrees with what's in the database.
     */
    private function saveProductModelWithSlugCollisionRetry(ProductModel $productModel, Product $product): void
    {
        $baseSlug = $product->slug();
        $maxRetries = 3;

        for ($retry = 0; $retry <= $maxRetries; $retry++) {
            $productModel->slug = $retry === 0 ? $baseSlug : $baseSlug.'-'.$retry;

            try {
                $productModel->save();

                if ($retry > 0) {
                    $product->changeSlug($productModel->slug);
                }

                return;
            } catch (QueryException $e) {
                if (! $this->isProductSlugUniqueViolation($e)) {
                    throw $e;
                }

                if ($retry === $maxRetries) {
                    throw new RuntimeException(
                        "Could not save Product: slug \"{$baseSlug}\" and its next {$maxRetries} numeric-suffix ".
                        "variants (\"{$baseSlug}-1\" .. \"{$baseSlug}-{$maxRetries}\") are all already taken."
                    );
                }
            }
        }
    }

    /**
     * Detects a violation of catalog_products_slug_unique — the
     * UNIQUE(slug) index from
     * 2026_08_23_000005_create_catalog_products_table.php — distinctly
     * from any other unique-constraint violation (e.g. base_sku, or the
     * variation signature/sku ones above). Same shared primary check as
     * isVariationSignatureUniqueViolation()
     * (isPossibleUniqueConstraintViolation()), with errorInfo[2] checked
     * for either MySQL's named index or SQLite's table.column pair —
     * never $e->getMessage() string matching.
     */
    private function isProductSlugUniqueViolation(QueryException $e): bool
    {
        if (! $this->isPossibleUniqueConstraintViolation($e)) {
            return false;
        }

        $driverErrorMessage = (string) ($e->errorInfo[2] ?? '');

        return str_contains($driverErrorMessage, 'catalog_products_slug_unique')
            || str_contains($driverErrorMessage, 'catalog_products.slug');
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
     * The primary check (isPossibleUniqueConstraintViolation()) is the
     * driver-reported SQLSTATE plus a driver-specific constraint error
     * code — never $e->getMessage() string matching, which is fragile
     * against driver/locale/version differences. errorInfo[2] (the
     * driver's own error text, not the exception's formatted message) is
     * used as a secondary narrowing to this specific index/columns, so a
     * constraint violation on some other unique column doesn't get
     * misreported as a duplicate variation combination. Checked in both
     * MySQL's format (the named index) and SQLite's format (the
     * table.column pair) — see isPossibleUniqueConstraintViolation()'s
     * docblock for why both are needed; the automated test suite runs
     * against SQLite (phpunit.xml), production runs MySQL, and this must
     * be correct under both.
     */
    private function isVariationSignatureUniqueViolation(QueryException $e): bool
    {
        if (! $this->isPossibleUniqueConstraintViolation($e)) {
            return false;
        }

        $driverErrorMessage = (string) ($e->errorInfo[2] ?? '');

        return str_contains($driverErrorMessage, 'catalog_variations_product_signature_unique')
            || str_contains($driverErrorMessage, 'catalog_variations.attribute_signature');
    }

    /**
     * Shared primary check for "was this QueryException possibly a
     * UNIQUE constraint violation at all": SQLSTATE 23000 plus a
     * driver-specific constraint error code. MySQL reports 1062
     * (ER_DUP_ENTRY) specifically for duplicate-key violations; SQLite
     * reports 19 (SQLITE_CONSTRAINT) for ANY constraint violation (UNIQUE,
     * NOT NULL, CHECK, FK alike) — confirmed directly against a real
     * SQLite connection, not assumed. That's exactly why this is only the
     * primary/possible check: every caller must still narrow further via
     * the driver's own error message (errorInfo[2]) to confirm it was
     * specifically the constraint being asked about.
     */
    private function isPossibleUniqueConstraintViolation(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];
        $sqlState = $errorInfo[0] ?? null;
        $driverErrorCode = (int) ($errorInfo[1] ?? 0);

        return $sqlState === '23000' && in_array($driverErrorCode, [1062, 19], true);
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
            slug: $model->slug,
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
