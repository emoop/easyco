<?php

namespace Tests\Feature;

use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests Product::setDescriptiveAttribute()/descriptiveAttributes()
 * round-tripping through EloquentProductRepository against real MySQL
 * — catalog-domain-design.md §3.11's real implementation, not just the
 * package-level unit coverage.
 */
class CatalogProductDescriptiveAttributeRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_text_descriptive_attribute_round_trips(): void
    {
        $definitionRepository = app(AttributeDefinitionRepository::class);
        $productRepository = app(ProductRepository::class);

        $material = new AttributeDefinition(id: null, code: 'material', name: 'Material', type: AttributeType::TEXT);
        $definitionRepository->save($material);

        $product = Product::createSimple('Plain Shirt', 'SKU-1', 'plain-shirt');
        $product->setDescriptiveAttribute($material, 'Cotton');
        $productRepository->save($product);

        $reloaded = $productRepository->findById($product->id());
        $descriptive = $reloaded->descriptiveAttributes();

        $this->assertSame(['Cotton'], array_values($descriptive));
        $this->assertArrayHasKey($material->id(), $descriptive);
        $this->assertSame('Cotton', $descriptive[$material->id()]);
    }

    /**
     * Confirms the real AttributeValue object comes back on reload —
     * not just its id.
     */
    public function test_a_select_descriptive_attribute_round_trips_the_real_attribute_value(): void
    {
        $definitionRepository = app(AttributeDefinitionRepository::class);
        $valueRepository = app(AttributeValueRepository::class);
        $productRepository = app(ProductRepository::class);

        $color = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        $definitionRepository->save($color);

        $black = new AttributeValue(id: null, attributeDefinitionId: $color->id(), value: 'Black');
        $valueRepository->save($black);

        $product = Product::createSimple('Plain Shirt', 'SKU-1', 'plain-shirt');
        $product->setDescriptiveAttribute($color, $black);
        $productRepository->save($product);

        $reloaded = $productRepository->findById($product->id());
        $descriptive = $reloaded->descriptiveAttributes();

        $this->assertArrayHasKey($color->id(), $descriptive);
        $reloadedValue = $descriptive[$color->id()];

        $this->assertInstanceOf(AttributeValue::class, $reloadedValue);
        $this->assertSame($black->id(), $reloadedValue->id());
        $this->assertSame('Black', $reloadedValue->value());
        $this->assertSame($color->id(), $reloadedValue->attributeDefinitionId());
    }

    public function test_setting_a_new_descriptive_attribute_and_saving_again_replaces_the_previous_set(): void
    {
        $definitionRepository = app(AttributeDefinitionRepository::class);
        $productRepository = app(ProductRepository::class);

        $material = new AttributeDefinition(id: null, code: 'material', name: 'Material', type: AttributeType::TEXT);
        $definitionRepository->save($material);

        $product = Product::createSimple('Plain Shirt', 'SKU-1', 'plain-shirt');
        $product->setDescriptiveAttribute($material, 'Cotton');
        $productRepository->save($product);

        $reloaded = $productRepository->findById($product->id());
        $reloaded->setDescriptiveAttribute($material, 'Polyester');
        $productRepository->save($reloaded);

        $final = $productRepository->findById($product->id());

        $this->assertSame('Polyester', $final->descriptiveAttributes()[$material->id()]);
    }

    public function test_a_product_with_no_descriptive_attributes_round_trips_an_empty_array(): void
    {
        $productRepository = app(ProductRepository::class);

        $product = Product::createSimple('Plain Shirt', 'SKU-1', 'plain-shirt');
        $productRepository->save($product);

        $reloaded = $productRepository->findById($product->id());

        $this->assertSame([], $reloaded->descriptiveAttributes());
    }
}
