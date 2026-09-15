<?php

namespace Tests\Feature;

use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Exceptions\CannotPublishEmptyVariableProductException;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests Product::rename()/publish()/markAsDraft()/archive()/
 * changeDescription()/changeBaseSku() round-tripping through
 * EloquentProductRepository against real MySQL — mirrors
 * CatalogProductSeasonRepositoryTest's established shape.
 */
class CatalogProductLifecycleRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_rename_then_save_persists_the_new_name(): void
    {
        $productRepository = app(ProductRepository::class);

        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $productRepository->save($product);

        $product->rename('Air Max 2026');
        $productRepository->save($product);

        $found = $productRepository->findById($product->id());

        $this->assertSame('Air Max 2026', $found->name());
    }

    public function test_publish_then_save_persists_active_status(): void
    {
        $productRepository = app(ProductRepository::class);

        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $productRepository->save($product);

        $product->publish();
        $productRepository->save($product);

        $found = $productRepository->findById($product->id());

        $this->assertSame(ProductStatus::ACTIVE, $found->status());
    }

    public function test_mark_as_draft_then_save_persists_draft_status(): void
    {
        $productRepository = app(ProductRepository::class);

        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $product->publish();
        $productRepository->save($product);

        $product->markAsDraft();
        $productRepository->save($product);

        $found = $productRepository->findById($product->id());

        $this->assertSame(ProductStatus::DRAFT, $found->status());
    }

    public function test_archive_then_save_persists_archived_status(): void
    {
        $productRepository = app(ProductRepository::class);

        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $productRepository->save($product);

        $product->archive();
        $productRepository->save($product);

        $found = $productRepository->findById($product->id());

        $this->assertSame(ProductStatus::ARCHIVED, $found->status());
    }

    public function test_change_description_then_save_persists_the_new_description(): void
    {
        $productRepository = app(ProductRepository::class);

        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $productRepository->save($product);

        $product->changeDescription('A classic silhouette.');
        $productRepository->save($product);

        $found = $productRepository->findById($product->id());

        $this->assertSame('A classic silhouette.', $found->description());
    }

    public function test_change_description_to_null_then_save_persists_a_cleared_description(): void
    {
        $productRepository = app(ProductRepository::class);

        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $product->changeDescription('A classic silhouette.');
        $productRepository->save($product);

        $product->changeDescription(null);
        $productRepository->save($product);

        $found = $productRepository->findById($product->id());

        $this->assertNull($found->description());
    }

    public function test_change_base_sku_then_save_persists_the_new_base_sku(): void
    {
        $productRepository = app(ProductRepository::class);

        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $productRepository->save($product);

        $product->changeBaseSku('SKU-2026');
        $productRepository->save($product);

        $found = $productRepository->findById($product->id());

        $this->assertSame('SKU-2026', $found->baseSku());
    }

    /**
     * Proves catalog_products_base_sku_unique really protects an
     * UPDATE, not just an INSERT — confirmed empirically, not assumed.
     */
    public function test_change_base_sku_to_a_value_already_used_by_a_different_product_throws(): void
    {
        $productRepository = app(ProductRepository::class);

        $existing = Product::createSimple('Air Force 1', 'SKU-TAKEN', 'air-force-1');
        $productRepository->save($existing);

        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $productRepository->save($product);

        $product->changeBaseSku('SKU-TAKEN');

        $this->expectException(QueryException::class);
        $productRepository->save($product);
    }

    /**
     * Proves hasAnyNonArchivedStandardVariation() correctly reads real
     * rehydrated Variation state on a reloaded instance, not something
     * that only worked by coincidence on the freshly-constructed
     * object in memory.
     */
    public function test_publishs_guard_survives_a_real_save_reload_round_trip(): void
    {
        $definitionRepository = app(AttributeDefinitionRepository::class);
        $valueRepository = app(AttributeValueRepository::class);
        $productRepository = app(ProductRepository::class);

        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        $definitionRepository->save($definition);
        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        $valueRepository->save($black);

        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black])]);
        $variation = $product->addStandardVariation([$definition->id() => $black->id()], 'SKU-2');
        $variation->archive();
        $productRepository->save($product);

        $reloaded = $productRepository->findByIdWithVariations($product->id());

        $this->expectException(CannotPublishEmptyVariableProductException::class);
        $reloaded->publish();
    }
}
