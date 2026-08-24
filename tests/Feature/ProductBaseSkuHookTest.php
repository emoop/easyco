<?php

namespace Tests\Feature;

use App\Providers\DemoHooksServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the EasyCo\Extensibility hook mechanism actually fires through a
 * real HTTP request: ProductController::store() applies the
 * 'catalog.product.base_sku' filter before creating the Product, and
 * DemoHooksServiceProvider's one demo listener (a proof-of-concept for the
 * hook mechanism, not the real SKU-generator feature — see that
 * provider's docblock) replaces the literal input "1" with a generated
 * value while leaving everything else unchanged.
 */
class ProductBaseSkuHookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The demo counter is an in-memory static (see
        // DemoHooksServiceProvider's docblock for why) and therefore
        // persists across test methods in this process unless reset.
        DemoHooksServiceProvider::resetDemoCounter();
    }

    public function test_posting_base_sku_1_triggers_the_demo_listener_and_generates_a_different_value(): void
    {
        $response = $this->postJson('/api/products', [
            'name' => 'Hook Demo Product',
            'base_sku' => '1',
        ]);

        $response->assertStatus(200);

        // No slug was posted either — the (independent, non-demo)
        // 'catalog.product.slug' listener still auto-generates one from
        // the name, unaffected by the base_sku demo listener.
        self::assertSame('hook-demo-product', $response->json('slug'));

        $productId = $response->json('product_id');
        self::assertNotNull($productId, 'response must include product_id');

        $this->assertDatabaseHas('catalog_products', [
            'id' => $productId,
            'name' => 'Hook Demo Product',
            'base_sku' => '100000',
            'slug' => 'hook-demo-product',
        ]);

        $this->assertDatabaseMissing('catalog_products', [
            'id' => $productId,
            'base_sku' => '1',
        ]);
    }

    public function test_posting_a_normal_base_sku_passes_through_the_hook_unchanged(): void
    {
        $response = $this->postJson('/api/products', [
            'name' => 'Untouched Product',
            'base_sku' => 'MY-REAL-SKU',
        ]);

        $response->assertStatus(200);

        self::assertSame('untouched-product', $response->json('slug'));

        $productId = $response->json('product_id');
        self::assertNotNull($productId, 'response must include product_id');

        $this->assertDatabaseHas('catalog_products', [
            'id' => $productId,
            'name' => 'Untouched Product',
            'base_sku' => 'MY-REAL-SKU',
            'slug' => 'untouched-product',
        ]);
    }

    public function test_the_demo_counter_increments_on_each_generated_value(): void
    {
        $first = $this->postJson('/api/products', ['name' => 'First', 'base_sku' => '1']);
        $second = $this->postJson('/api/products', ['name' => 'Second', 'base_sku' => '1']);

        $first->assertStatus(200);
        $second->assertStatus(200);

        // Distinct names, so distinct auto-generated slugs — no collision,
        // no numeric suffix needed (that path is covered separately in
        // CatalogSlugGeneratorTest).
        self::assertSame('first', $first->json('slug'));
        self::assertSame('second', $second->json('slug'));

        $this->assertDatabaseHas('catalog_products', ['name' => 'First', 'base_sku' => '100000', 'slug' => 'first']);
        $this->assertDatabaseHas('catalog_products', ['name' => 'Second', 'base_sku' => '100001', 'slug' => 'second']);
    }
}
