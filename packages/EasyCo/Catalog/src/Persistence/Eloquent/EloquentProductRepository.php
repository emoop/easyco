<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Enums\ProductType;
use EasyCo\Catalog\Enums\VariationStatus;
use EasyCo\Catalog\Enums\VariationType;
use EasyCo\Catalog\Exceptions\DuplicateVariationCombinationException;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Variation;
use EasyCo\Catalog\VariationAxis;
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

            $this->persistVariationAxes($productModel, $product);

            foreach ($product->variations() as $variation) {
                $this->saveVariation($productModel, $product, $variation);
            }
        });
    }

    /**
     * Persists this Product's declared variation axes (catalog_product_attributes
     * with is_variation_axis=true, plus catalog_product_axis_values for
     * each axis's enabled values) — the storage-layer counterpart of
     * Product::declareVariationAxes(), which is itself a full replace, not
     * an incremental add (catalog-domain-design.md §3.5). Mirrors that
     * semantics here: delete this product's existing axis-declaration and
     * allowed-value rows, then reinsert exactly what's currently declared
     * in memory. A no-op for a SIMPLE product or a VARIABLE product with
     * no axes declared yet.
     *
     * Scoped deliberately to is_variation_axis=true rows only — generic
     * descriptive attributes (is_variation_axis=false) have no domain
     * representation on Product yet and this method never touches them.
     */
    private function persistVariationAxes(ProductModel $productModel, Product $product): void
    {
        DB::table('catalog_product_axis_values')
            ->where('product_id', $productModel->id)
            ->delete();

        DB::table('catalog_product_attributes')
            ->where('product_id', $productModel->id)
            ->where('is_variation_axis', true)
            ->delete();

        $axes = $product->variationAxes();

        if ($axes === []) {
            return;
        }

        $now = now();
        $attributeRows = [];
        $axisValueRows = [];

        foreach ($axes as $axis) {
            $attributeRows[] = [
                'product_id' => $productModel->id,
                'attribute_definition_id' => $axis->attributeDefinitionId(),
                'is_variation_axis' => true,
                'text_value' => null,
                'attribute_value_id' => null,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            foreach ($axis->allowedValueIds() as $attributeValueId) {
                $axisValueRows[] = [
                    'product_id' => $productModel->id,
                    'attribute_definition_id' => $axis->attributeDefinitionId(),
                    'attribute_value_id' => $attributeValueId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('catalog_product_attributes')->insert($attributeRows);
        DB::table('catalog_product_axis_values')->insert($axisValueRows);
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
        $variationModel->barcode = $variation->barcode();
        $variationModel->is_visible = $variation->isVisible();
        $variationModel->is_purchasable = $variation->isPurchasable();
        $variationModel->short_description = $variation->shortDescription();
        $variationModel->shipping_class = $variation->shippingClass();
        $variationModel->weight_grams = $variation->weightGrams();
        $variationModel->length_mm = $variation->lengthMm();
        $variationModel->width_mm = $variation->widthMm();
        $variationModel->height_mm = $variation->heightMm();

        $this->saveVariationModelWithSkuCollisionRetry($variationModel, $product, $variation);

        if ($variation->id() === null) {
            $variation->assignId((string) $variationModel->id);
            $product->reindexVariationAfterPersist($variation);
        }

        $this->syncVariationAttributeAssignments($variationModel, $variation);
    }

    /**
     * Persists $variationModel, retrying up to 3 times on a sku UNIQUE
     * constraint collision by appending an incrementing numeric suffix
     * to the sku (e.g. "154215-2" -> "154215-2-1" -> "-2" -> "-3") before
     * giving up and throwing. Mirrors
     * saveProductModelWithSlugCollisionRetry() exactly — same shape, same
     * reasoning: the 'catalog.variation.sku' Hook listener
     * (App\Providers\CatalogSkuGeneratorServiceProvider) picks its
     * candidate sku from count($product->variations()) + 1, a
     * best-effort in-memory guess that can still collide (e.g. a
     * variation was manually given a sku matching that exact pattern, or
     * a gap from an archived variation coincides) — this retry, driven
     * by the actual UNIQUE(sku) constraint, is the authoritative,
     * race-condition-safe guarantee.
     *
     * A signature collision is NOT retried here — that means a genuine
     * duplicate combination, not a sku-naming clash, so it's translated
     * to DuplicateVariationCombinationException immediately, same as
     * before this method existed.
     *
     * On success with an appended suffix, updates $variation's own sku
     * via setSku() so the in-memory Variation reflects exactly what was
     * actually persisted — same reasoning as the slug retry's
     * $product->changeSlug() call.
     */
    private function saveVariationModelWithSkuCollisionRetry(VariationModel $variationModel, Product $product, Variation $variation): void
    {
        $baseSku = $variation->sku();
        $maxRetries = 3;

        for ($retry = 0; $retry <= $maxRetries; $retry++) {
            $variationModel->sku = $retry === 0 ? $baseSku : $baseSku.'-'.$retry;

            try {
                $variationModel->save();

                if ($retry > 0) {
                    $variation->setSku($variationModel->sku);
                }

                return;
            } catch (QueryException $e) {
                if ($this->isVariationSignatureUniqueViolation($e)) {
                    throw DuplicateVariationCombinationException::fromDatabaseConstraintViolation(
                        $product->id() ?? '(unsaved)',
                        $e
                    );
                }

                if (! $this->isVariationSkuUniqueViolation($e)) {
                    throw $e;
                }

                if ($retry === $maxRetries) {
                    throw new RuntimeException(
                        "Could not save Variation: sku \"{$baseSku}\" and its next {$maxRetries} numeric-suffix ".
                        "variants (\"{$baseSku}-1\" .. \"{$baseSku}-{$maxRetries}\") are all already taken."
                    );
                }
            }
        }
    }

    /**
     * Detects a violation of catalog_variations_sku_unique — the
     * UNIQUE(sku) index from
     * 2026_08_23_000006_create_catalog_variations_table.php. Same shared
     * primary check as isVariationSignatureUniqueViolation() /
     * isProductSlugUniqueViolation() (isPossibleUniqueConstraintViolation()),
     * with errorInfo[2] checked for either MySQL's named index or
     * SQLite's table.column pair — never $e->getMessage() string
     * matching (catalog-domain-design.md §7).
     */
    private function isVariationSkuUniqueViolation(QueryException $e): bool
    {
        if (! $this->isPossibleUniqueConstraintViolation($e)) {
            return false;
        }

        $driverErrorMessage = (string) ($e->errorInfo[2] ?? '');

        return str_contains($driverErrorMessage, 'catalog_variations_sku_unique')
            || str_contains($driverErrorMessage, 'catalog_variations.sku');
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

        $type = ProductType::from($model->type);
        $variationAxes = $type === ProductType::VARIABLE ? $this->loadVariationAxes($model->id) : [];

        return Product::reconstituteFromStorage(
            id: (string) $model->id,
            name: $model->name,
            type: $type,
            baseSku: $model->base_sku,
            slug: $model->slug,
            status: ProductStatus::from($model->status),
            catalogVisibility: CatalogVisibility::from($model->catalog_visibility),
            variations: $variations,
            variationAxes: $variationAxes,
        );
    }

    /**
     * Loads this Product's declared variation axes from
     * catalog_product_attributes (is_variation_axis=true) joined with
     * catalog_product_axis_values, and rebuilds the corresponding
     * AttributeDefinition/AttributeValue/VariationAxis domain objects —
     * the storage-layer counterpart of persistVariationAxes(). This is
     * what closes the gap documented in catalog-domain-design.md §6 and
     * vertical-slice-notes.md §2: a reloaded VARIABLE product can
     * immediately call addStandardVariation()/changeVariationCombination()
     * and have it validate correctly against its real declared axes,
     * instead of silently accepting any combination because no axes were
     * ever reloaded.
     *
     * @return VariationAxis[]
     */
    private function loadVariationAxes(int $productId): array
    {
        $axisDefinitionIds = DB::table('catalog_product_attributes')
            ->where('product_id', $productId)
            ->where('is_variation_axis', true)
            ->pluck('attribute_definition_id');

        if ($axisDefinitionIds->isEmpty()) {
            return [];
        }

        $definitionModels = AttributeDefinitionModel::whereIn('id', $axisDefinitionIds)->get()->keyBy('id');

        $axisValueRows = DB::table('catalog_product_axis_values')
            ->where('product_id', $productId)
            ->whereIn('attribute_definition_id', $axisDefinitionIds)
            ->get(['attribute_definition_id', 'attribute_value_id']);

        $valueIdsByDefinitionId = [];
        foreach ($axisValueRows as $row) {
            $valueIdsByDefinitionId[$row->attribute_definition_id][] = $row->attribute_value_id;
        }

        $valueModels = AttributeValueModel::whereIn('id', $axisValueRows->pluck('attribute_value_id')->unique())
            ->get()
            ->keyBy('id');

        $axes = [];
        foreach ($axisDefinitionIds as $definitionId) {
            $definitionModel = $definitionModels->get($definitionId);
            if ($definitionModel === null) {
                continue;
            }

            $definition = new AttributeDefinition(
                id: (string) $definitionModel->id,
                code: $definitionModel->code,
                name: $definitionModel->name,
                type: AttributeType::from($definitionModel->type),
            );

            $allowedValues = [];
            foreach ($valueIdsByDefinitionId[$definitionId] ?? [] as $valueId) {
                $valueModel = $valueModels->get($valueId);
                if ($valueModel === null) {
                    continue;
                }

                $allowedValues[] = new AttributeValue(
                    id: (string) $valueModel->id,
                    attributeDefinitionId: (string) $valueModel->attribute_definition_id,
                    value: $valueModel->value,
                    sortOrder: $valueModel->sort_order,
                );
            }

            if ($allowedValues === []) {
                // Defensive only: persistVariationAxes() never writes an
                // axis-declaration row without at least one allowed-value
                // row, and VariationAxis's own constructor would reject an
                // empty set anyway — skip rather than let a read throw on
                // data that should never exist.
                continue;
            }

            $axes[] = new VariationAxis($definition, $allowedValues);
        }

        return $axes;
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
