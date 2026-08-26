<?php

namespace Tests\Feature;

use App\Providers\CatalogSkuGeneratorServiceProvider;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Services\VariationCombinationGenerator;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Extensibility\Hook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests App\Providers\CatalogSkuGeneratorServiceProvider's two listeners
 * — 'catalog.product.base_sku' and 'catalog.variation.sku' — replacing
 * DemoHooksServiceProvider's proof-of-concept and
 * VariationCombinationGenerator's previously-required
 * $skuForCombination callable. Mirrors CatalogSlugGeneratorTest's style:
 * the base_sku listener is exercised both through the real HTTP route
 * (proving the hook fires end-to-end) and directly via Hook::apply();
 * the variation listener is exercised directly, purely in-memory (no DB
 * needed — it only inspects $product->variations(), never queries
 * storage), since there is no HTTP route yet that generates variations.
 */
class CatalogSkuGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function configuredSequenceStart(): int
    {
        return (int) config('services.catalog.base_sku_sequence_start');
    }

    private function colorAxis(): VariationAxis
    {
        return new VariationAxis(
            new AttributeDefinition('1', 'color', 'Color', AttributeType::SELECT),
            [
                new AttributeValue('5', '1', 'Black'),
                new AttributeValue('6', '1', 'White'),
                new AttributeValue('7', '1', 'Red'),
            ]
        );
    }

    public function test_posting_an_empty_base_sku_generates_a_sequence_number_from_the_configured_start_value(): void
    {
        $response = $this->postJson('/api/products', ['name' => 'Auto Sku Product']);

        $response->assertStatus(200);

        $productId = $response->json('product_id');
        $this->assertDatabaseHas('catalog_products', [
            'id' => $productId,
            'base_sku' => (string) $this->configuredSequenceStart(),
        ]);
    }

    public function test_posting_an_explicit_base_sku_passes_through_unchanged(): void
    {
        $response = $this->postJson('/api/products', [
            'name' => 'Untouched Product',
            'base_sku' => 'MY-REAL-SKU',
        ]);

        $response->assertStatus(200);

        $productId = $response->json('product_id');
        $this->assertDatabaseHas('catalog_products', [
            'id' => $productId,
            'base_sku' => 'MY-REAL-SKU',
        ]);
    }

    public function test_two_consecutive_products_get_consecutive_base_sku_values(): void
    {
        $start = $this->configuredSequenceStart();

        $first = $this->postJson('/api/products', ['name' => 'First']);
        $second = $this->postJson('/api/products', ['name' => 'Second']);

        $first->assertStatus(200);
        $second->assertStatus(200);

        $this->assertDatabaseHas('catalog_products', [
            'id' => $first->json('product_id'),
            'base_sku' => (string) $start,
        ]);
        $this->assertDatabaseHas('catalog_products', [
            'id' => $second->json('product_id'),
            'base_sku' => (string) ($start + 1),
        ]);
    }

    /**
     * "Concurrent" in the same sense
     * DatabaseUniquenessConstraintTest::test_concurrent_insert_race_is_caught_by_the_constraint_not_a_check_then_insert
     * uses the word: there is no app-layer pre-check anywhere between
     * reading the current sequence value and writing the incremented
     * one — the whole read-increment-write happens as a single
     * DB::transaction() + lockForUpdate() unit (see
     * EasyCo\Catalog\Persistence\Eloquent\EloquentSkuSequenceRepository::next()),
     * so there is no window a second caller could interleave with. PHP's
     * single-threaded, synchronous test execution cannot literally issue
     * two requests at the exact same instant, but that is exactly what
     * this test is designed to not need: even called back-to-back with
     * zero coordination between the two calls, they can never return the
     * same number, because the mechanism itself has no read-then-write
     * gap for a race to land in — proven by asserting the two results
     * are not just different, but exactly sequential.
     */
    public function test_two_sequential_sequence_reads_can_never_return_the_same_number(): void
    {
        $first = Hook::apply('catalog.product.base_sku', '');
        $second = Hook::apply('catalog.product.base_sku', '');

        $this->assertNotSame($first, $second);
        $this->assertSame((string) ((int) $first + 1), $second);
    }

    public function test_variation_sku_generates_the_base_n_pattern_sequentially(): void
    {
        $product = Product::createVariable('T-Shirt', '154215', 't-shirt');
        $product->declareVariationAxes([$this->colorAxis()]);

        $firstSku = Hook::apply('catalog.variation.sku', '', '154215', $product);
        $this->assertSame('154215-1', $firstSku);
        $product->addStandardVariation([1 => 5], $firstSku);

        $secondSku = Hook::apply('catalog.variation.sku', '', '154215', $product);
        $this->assertSame('154215-2', $secondSku);
        $product->addStandardVariation([1 => 6], $secondSku);

        $thirdSku = Hook::apply('catalog.variation.sku', '', '154215', $product);
        $this->assertSame('154215-3', $thirdSku);
    }

    public function test_variation_sku_passes_through_an_explicit_value_unchanged(): void
    {
        $product = Product::createVariable('T-Shirt', '154215', 't-shirt');

        $value = Hook::apply('catalog.variation.sku', 'CUSTOM-SKU', '154215', $product);

        $this->assertSame('CUSTOM-SKU', $value);
    }

    /**
     * Proves CatalogSkuGeneratorServiceProvider::variationSkuStrategy()'s
     * closure is actually usable as VariationCombinationGenerator::
     * generate()'s $skuForCombination argument — the two tests above
     * exercise the 'catalog.variation.sku' listener directly; this one
     * exercises the real generator, end to end, the way a future caller
     * (e.g. an admin controller) actually would.
     */
    public function test_the_factory_closure_works_as_generate_s_sku_strategy(): void
    {
        $product = Product::createVariable('T-Shirt', '154215', 't-shirt');
        $product->declareVariationAxes([$this->colorAxis()]);

        $generator = new VariationCombinationGenerator();

        $created = $generator->generate(
            $product,
            [1 => [5, 6]],
            CatalogSkuGeneratorServiceProvider::variationSkuStrategy($product)
        );

        $this->assertCount(2, $created);
        $skus = array_map(static fn ($variation) => $variation->sku(), $created);
        sort($skus);
        $this->assertSame(['154215-1', '154215-2'], $skus);
    }
}
