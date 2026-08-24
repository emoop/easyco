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

        $productId = $response->json('product_id');
        self::assertNotNull($productId, 'response must include product_id');

        $this->assertDatabaseHas('catalog_products', [
            'id' => $productId,
            'name' => 'Hook Demo Product',
            'base_sku' => '100000',
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

        $productId = $response->json('product_id');
        self::assertNotNull($productId, 'response must include product_id');

        $this->assertDatabaseHas('catalog_products', [
            'id' => $productId,
            'name' => 'Untouched Product',
            'base_sku' => 'MY-REAL-SKU',
        ]);
    }

    public function test_the_demo_counter_increments_on_each_generated_value(): void
    {
        $first = $this->postJson('/api/products', ['name' => 'First', 'base_sku' => '1']);
        $second = $this->postJson('/api/products', ['name' => 'Second', 'base_sku' => '1']);

        $first->assertStatus(200);
        $second->assertStatus(200);

        $this->assertDatabaseHas('catalog_products', ['name' => 'First', 'base_sku' => '100000']);
        $this->assertDatabaseHas('catalog_products', ['name' => 'Second', 'base_sku' => '100001']);
    }
}
