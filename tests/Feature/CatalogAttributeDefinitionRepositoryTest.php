<?php

namespace Tests\Feature;

use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests EloquentAttributeDefinitionRepository's rename() round trip
 * against real MySQL — the save/findById/all shape itself is already
 * exercised elsewhere (this repository predates this task); only the
 * new mutator's persistence needs proving here.
 */
class CatalogAttributeDefinitionRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_rename_then_save_persists_the_new_name(): void
    {
        $repository = app(AttributeDefinitionRepository::class);

        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        $repository->save($definition);

        $definition->rename('Colour');
        $repository->save($definition);

        $reloaded = $repository->findById($definition->id());

        $this->assertSame('Colour', $reloaded->name());
        $this->assertSame('color', $reloaded->code());
        $this->assertSame(AttributeType::SELECT, $reloaded->type());
    }

    public function test_count_products_using_returns_zero_for_both_when_unused(): void
    {
        $repository = app(AttributeDefinitionRepository::class);

        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        $repository->save($definition);

        $this->assertSame(['descriptive' => 0, 'axis' => 0], $repository->countProductsUsing($definition->id()));
    }

    /**
     * Proves descriptive and axis counts are genuinely independent —
     * the same definition used descriptively on one product and as a
     * real variation axis (via a real VARIABLE product with a real
     * Variation) on another must report 1-and-1, never 2-and-0 or
     * 0-and-2.
     */
    public function test_count_products_using_counts_descriptive_and_axis_independently(): void
    {
        $definitionRepository = app(AttributeDefinitionRepository::class);
        $valueRepository = app(AttributeValueRepository::class);
        $productRepository = app(ProductRepository::class);

        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        $definitionRepository->save($definition);

        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        $valueRepository->save($black);

        // Descriptive use, on a SIMPLE product. No domain-layer method
        // exists yet for setting a descriptive attribute on Product —
        // catalog-domain-design.md §3.11's Product-level representation
        // is a separate, not-yet-built concern (confirmed: Product.php
        // has no descriptiveAttributes()/setDescriptiveAttribute()/
        // hasVariationAxis() at all) — so this test writes the row
        // directly, exactly as the future application-layer write path
        // will, to prove the READ side (this repository method) works
        // correctly against the real schema regardless.
        $descriptiveProduct = Product::createSimple('Plain Shirt', 'SKU-1', 'plain-shirt');
        $productRepository->save($descriptiveProduct);

        DB::table('catalog_product_attributes')->insert([
            'product_id' => $descriptiveProduct->id(),
            'attribute_definition_id' => $definition->id(),
            'is_variation_axis' => false,
            'attribute_value_id' => $black->id(),
            'text_value' => null,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Axis use, on a real VARIABLE product with a real Variation —
        // fully through the domain layer.
        $axisProduct = Product::createVariable('T-Shirt', 'SKU-2', 't-shirt');
        $axisProduct->declareVariationAxes([new VariationAxis($definition, [$black])]);
        $axisProduct->addStandardVariation([$definition->id() => $black->id()], 'SKU-2-BLACK');
        $productRepository->save($axisProduct);

        $counts = $definitionRepository->countProductsUsing($definition->id());

        $this->assertSame(['descriptive' => 1, 'axis' => 1], $counts);
    }
}
