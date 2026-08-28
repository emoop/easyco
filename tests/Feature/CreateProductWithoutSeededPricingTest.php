<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deliberately does NOT override the PriceResolver binding (unlike
 * CreateProductVerticalSliceTest, which always swaps in a test double) —
 * this exercises the real container-resolved EloquentPriceResolver against
 * a genuinely fresh test database, where the reserved "Regular Prices"
 * system PriceList has not been seeded yet (§8 item 3, not yet
 * implemented). Proves ProductController's RuntimeException catch and
 * EloquentPriceResolver's fail-loud RuntimeException actually work
 * together correctly today, not just each in isolation against a mock.
 */
class CreateProductWithoutSeededPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_product_against_the_real_resolver_with_no_regular_prices_list_seeded_yet(): void
    {
        $response = $this->postJson('/api/products', ['name' => 'Unseeded Pricing Product', 'base_sku' => 'TEST-SKU-UNSEEDED']);

        $response->assertStatus(200);

        $body = $response->json();

        self::assertSame('Unseeded Pricing Product', $body['name']);
        self::assertNull($body['price']);
        self::assertTrue($body['price_unavailable'] ?? false);

        $productId = $body['product_id'] ?? null;
        self::assertNotNull($productId, 'response must include product_id');

        $this->assertDatabaseHas('catalog_products', [
            'id' => $productId,
            'name' => 'Unseeded Pricing Product',
        ]);
    }
}
