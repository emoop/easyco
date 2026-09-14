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
 * Tests EloquentAttributeValueRepository's rename()/changeSortOrder()
 * round trip against real MySQL — the save/findById shape itself
 * predates this task; only the new mutators' persistence needs proving
 * here.
 */
class CatalogAttributeValueRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function persistedDefinition(): AttributeDefinition
    {
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        return $definition;
    }

    public function test_rename_then_save_persists_the_new_value(): void
    {
        $repository = app(AttributeValueRepository::class);
        $definition = $this->persistedDefinition();

        $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        $repository->save($value);

        $value->rename('Jet Black');
        $repository->save($value);

        $reloaded = $repository->findById($value->id());

        $this->assertSame('Jet Black', $reloaded->value());
    }

    public function test_change_sort_order_then_save_persists_the_new_order(): void
    {
        $repository = app(AttributeValueRepository::class);
        $definition = $this->persistedDefinition();

        $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black', sortOrder: 0);
        $repository->save($value);

        $value->changeSortOrder(5);
        $repository->save($value);

        $reloaded = $repository->findById($value->id());

        $this->assertSame(5, $reloaded->sortOrder());
    }

    public function test_count_products_using_returns_zero_for_both_when_unused(): void
    {
        $repository = app(AttributeValueRepository::class);
        $definition = $this->persistedDefinition();

        $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        $repository->save($value);

        $this->assertSame(['descriptive' => 0, 'axis' => 0], $repository->countProductsUsing($value->id()));
    }

    /**
     * Proves descriptive and axis counts are genuinely independent for
     * a real AttributeValue — same shape as
     * CatalogAttributeDefinitionRepositoryTest's identical test.
     */
    public function test_count_products_using_counts_descriptive_and_axis_independently(): void
    {
        $valueRepository = app(AttributeValueRepository::class);
        $productRepository = app(ProductRepository::class);
        $definition = $this->persistedDefinition();

        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        $valueRepository->save($black);

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

        $axisProduct = Product::createVariable('T-Shirt', 'SKU-2', 't-shirt');
        $axisProduct->declareVariationAxes([new VariationAxis($definition, [$black])]);
        $axisProduct->addStandardVariation([$definition->id() => $black->id()], 'SKU-2-BLACK');
        $productRepository->save($axisProduct);

        $counts = $valueRepository->countProductsUsing($black->id());

        $this->assertSame(['descriptive' => 1, 'axis' => 1], $counts);
    }

    /**
     * The specific case DISTINCT exists to handle: two Variations of
     * the SAME Product both choosing the same axis value (e.g. two
     * SKUs, both "Color: Black", at different sizes) must count as ONE
     * product using this value, not two.
     */
    public function test_count_products_using_axis_count_deduplicates_multiple_variations_of_the_same_product(): void
    {
        $valueRepository = app(AttributeValueRepository::class);
        $definitionRepository = app(AttributeDefinitionRepository::class);
        $productRepository = app(ProductRepository::class);

        $color = $this->persistedDefinition();
        $black = new AttributeValue(id: null, attributeDefinitionId: $color->id(), value: 'Black');
        $valueRepository->save($black);

        $size = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT);
        $definitionRepository->save($size);
        $small = new AttributeValue(id: null, attributeDefinitionId: $size->id(), value: 'Small');
        $valueRepository->save($small);
        $medium = new AttributeValue(id: null, attributeDefinitionId: $size->id(), value: 'Medium');
        $valueRepository->save($medium);

        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([
            new VariationAxis($color, [$black]),
            new VariationAxis($size, [$small, $medium]),
        ]);
        $product->addStandardVariation([$color->id() => $black->id(), $size->id() => $small->id()], 'SKU-1-BLACK-S');
        $product->addStandardVariation([$color->id() => $black->id(), $size->id() => $medium->id()], 'SKU-1-BLACK-M');
        $productRepository->save($product);

        $counts = $valueRepository->countProductsUsing($black->id());

        $this->assertSame(1, $counts['axis']);
    }

    /**
     * N=3, not just N=1, per this task's own explicit instruction — to
     * catch an accidental "always returns 1" bug in the axis count.
     */
    public function test_count_products_using_axis_count_across_three_real_products(): void
    {
        $valueRepository = app(AttributeValueRepository::class);
        $productRepository = app(ProductRepository::class);
        $color = $this->persistedDefinition();
        $black = new AttributeValue(id: null, attributeDefinitionId: $color->id(), value: 'Black');
        $valueRepository->save($black);

        for ($i = 1; $i <= 3; $i++) {
            $product = Product::createVariable("T-Shirt {$i}", "SKU-{$i}", "t-shirt-{$i}");
            $product->declareVariationAxes([new VariationAxis($color, [$black])]);
            $product->addStandardVariation([$color->id() => $black->id()], "SKU-{$i}-BLACK");
            $productRepository->save($product);
        }

        $counts = $valueRepository->countProductsUsing($black->id());

        $this->assertSame(3, $counts['axis']);
    }
}
