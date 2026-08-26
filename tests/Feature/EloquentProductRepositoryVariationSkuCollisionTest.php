<?php

namespace Tests\Feature;

use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
use EasyCo\Catalog\Persistence\Eloquent\AttributeValueModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Tests EloquentProductRepository::save()'s own sku UNIQUE-constraint
 * collision retry directly — the authoritative, DB-constraint-driven
 * safety net (as opposed to CatalogSkuGeneratorTest, which covers the
 * best-effort app-layer candidate in the 'catalog.variation.sku' hook
 * listener). Bypasses the hook entirely: variations here are given
 * explicit, deliberately colliding skus, so the real MySQL/SQLite
 * UNIQUE(sku) index on catalog_variations is what's actually caught and
 * retried. Mirrors EloquentProductRepositorySlugCollisionTest exactly.
 */
class EloquentProductRepositoryVariationSkuCollisionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One Color axis with 5 enabled values — enough distinct combinations
     * for both tests below without needing a second axis.
     *
     * @return array{0: VariationAxis, 1: int, 2: int[]} [axis, attributeDefinitionId, valueIds]
     */
    private function colorAxisWithFiveValues(): array
    {
        $definition = AttributeDefinitionModel::create(['code' => 'color', 'name' => 'Color', 'type' => 'select']);

        $names = ['Black', 'White', 'Red', 'Blue', 'Green'];
        $valueModels = [];
        foreach ($names as $i => $name) {
            $valueModels[] = AttributeValueModel::create([
                'attribute_definition_id' => $definition->id,
                'value' => $name,
                'sort_order' => $i,
            ]);
        }

        $axis = new VariationAxis(
            new AttributeDefinition((string) $definition->id, $definition->code, $definition->name, AttributeType::SELECT),
            array_map(
                fn ($model) => new AttributeValue((string) $model->id, (string) $definition->id, $model->value),
                $valueModels
            )
        );

        $valueIds = array_map(static fn ($model) => (int) $model->id, $valueModels);

        return [$axis, (int) $definition->id, $valueIds];
    }

    public function test_saving_a_second_variation_with_a_colliding_sku_retries_with_a_numeric_suffix(): void
    {
        [$axis, $colorId, $valueIds] = $this->colorAxisWithFiveValues();

        $repository = app(ProductRepository::class);

        $product = Product::createVariable('T-Shirt', '154215', 't-shirt');
        $product->declareVariationAxes([$axis]);

        $blackVariation = $product->addStandardVariation([$colorId => $valueIds[0]], '154215-2');
        $repository->save($product);

        // A second, genuinely distinct combination (White, not Black) is
        // deliberately given the exact same colliding sku.
        $whiteVariation = $product->addStandardVariation([$colorId => $valueIds[1]], '154215-2');
        $repository->save($product);

        $this->assertSame('154215-2', $blackVariation->sku());
        $this->assertSame(
            '154215-2-1',
            $whiteVariation->sku(),
            'the in-memory Variation must reflect exactly what was actually persisted after a retry'
        );

        $this->assertDatabaseHas('catalog_variations', ['id' => $blackVariation->id(), 'sku' => '154215-2']);
        $this->assertDatabaseHas('catalog_variations', ['id' => $whiteVariation->id(), 'sku' => '154215-2-1']);
    }

    public function test_exhausting_all_retries_throws_a_clear_exception(): void
    {
        [$axis, $colorId, $valueIds] = $this->colorAxisWithFiveValues();

        $repository = app(ProductRepository::class);

        $product = Product::createVariable('T-Shirt', '154215', 't-shirt');
        $product->declareVariationAxes([$axis]);

        // Occupy the base sku and all 3 suffix variants the retry loop
        // will try, using 4 distinct combinations, so a 5th variation has
        // nowhere left to land.
        foreach (['exhausted-sku', 'exhausted-sku-1', 'exhausted-sku-2', 'exhausted-sku-3'] as $i => $sku) {
            $product->addStandardVariation([$colorId => $valueIds[$i]], $sku);
        }
        $repository->save($product);

        $product->addStandardVariation([$colorId => $valueIds[4]], 'exhausted-sku');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/exhausted-sku/');

        $repository->save($product);
    }
}
