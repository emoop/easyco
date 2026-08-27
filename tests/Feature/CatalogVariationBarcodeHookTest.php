<?php

namespace Tests\Feature;

use EasyCo\Extensibility\Hook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 'catalog.variation.barcode' deliberately ships with NO listener — see
 * extensibility-design-and-hooks.md's Hook Reference entry. This test
 * proves the resulting no-op behavior explicitly: with zero listeners
 * registered, HookRegistry::applyFilters() (per its own tests) must
 * return $value unchanged, so a merchant-supplied barcode passes through
 * exactly as typed and an empty barcode stays empty. Exercised both via
 * the real HTTP route (App\Http\Controllers\Api\ProductController::store())
 * and directly via Hook::apply(), mirroring CatalogSkuGeneratorTest's style.
 */
class CatalogVariationBarcodeHookTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_supplied_barcode_passes_through_unchanged_with_no_listeners_registered(): void
    {
        $response = $this->postJson('/api/products', [
            'name' => 'Barcoded Product',
            'barcode' => '5901234123457',
        ]);

        $response->assertStatus(200);

        $productId = $response->json('product_id');
        $this->assertDatabaseHas('catalog_variations', [
            'product_id' => $productId,
            'barcode' => '5901234123457',
        ]);
    }

    public function test_an_empty_barcode_stays_empty_with_no_listeners_registered(): void
    {
        $response = $this->postJson('/api/products', [
            'name' => 'No Barcode Product',
        ]);

        $response->assertStatus(200);

        $productId = $response->json('product_id');
        $this->assertDatabaseHas('catalog_variations', [
            'product_id' => $productId,
            'barcode' => null,
        ]);
    }

    public function test_hook_apply_is_a_pure_no_op_with_zero_listeners(): void
    {
        $this->assertSame('MY-BARCODE', Hook::apply('catalog.variation.barcode', 'MY-BARCODE', null));
        $this->assertSame('', Hook::apply('catalog.variation.barcode', '', null));
    }
}
