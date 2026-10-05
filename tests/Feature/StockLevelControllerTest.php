<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Persistence\Eloquent\StockLevelModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockLevelControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdministrator();
    }

    private static int $productCounter = 0;

    private function variationId(): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    public function test_get_for_a_variation_with_no_stock_row_returns_200_with_zero_quantity(): void
    {
        $variationId = $this->variationId();

        $response = $this->getJson("/api/variations/{$variationId}/stock");

        $response->assertStatus(200);
        $response->assertJsonPath('variation_id', $variationId);
        $response->assertJsonPath('quantity', 0);
        $this->assertSame(0, StockLevelModel::count());
    }

    public function test_get_for_a_nonexistent_variation_returns_422(): void
    {
        $response = $this->getJson('/api/variations/999999/stock');

        $response->assertStatus(422);
    }

    public function test_put_happy_path_returns_200_and_persists(): void
    {
        $variationId = $this->variationId();

        $response = $this->putJson("/api/variations/{$variationId}/stock", ['quantity' => 50]);

        $response->assertStatus(200);
        $response->assertJsonPath('variation_id', $variationId);
        $response->assertJsonPath('quantity', 50);

        $this->assertSame(1, StockLevelModel::count());
        $this->assertSame(50, StockLevelModel::first()->quantity);
    }

    public function test_put_with_a_negative_quantity_returns_422(): void
    {
        $variationId = $this->variationId();

        $response = $this->putJson("/api/variations/{$variationId}/stock", ['quantity' => -1]);

        $response->assertStatus(422);
        $this->assertSame(0, StockLevelModel::count());
    }

    public function test_put_for_a_nonexistent_variation_returns_422(): void
    {
        $response = $this->putJson('/api/variations/999999/stock', ['quantity' => 10]);

        $response->assertStatus(422);
    }

    public function test_put_called_twice_still_exactly_one_row(): void
    {
        $variationId = $this->variationId();

        $this->putJson("/api/variations/{$variationId}/stock", ['quantity' => 10])->assertStatus(200);
        $this->putJson("/api/variations/{$variationId}/stock", ['quantity' => 25])->assertStatus(200);

        $this->assertSame(1, StockLevelModel::count());
        $this->assertSame(25, StockLevelModel::first()->quantity);
    }

    // --- Input hardening pass 2: the quantity ceiling -----------------------------------

    /**
     * stock_levels.quantity is an unsignedInteger, so 4294967295 is the widest
     * value the column can hold and must be a legitimate quantity, not a
     * refusal. A narrower business cap is a later decision.
     */
    public function test_a_quantity_of_the_columns_own_maximum_is_accepted_and_stored_unchanged(): void
    {
        $variationId = $this->variationId();

        $response = $this->putJson("/api/variations/{$variationId}/stock", ['quantity' => 4294967295]);

        $response->assertStatus(200);
        $response->assertJsonPath('quantity', 4294967295);

        $this->assertSame(1, StockLevelModel::count());
        $this->assertSame(4294967295, StockLevelModel::first()->quantity);
    }

    public function test_a_quantity_one_above_the_columns_own_maximum_is_a_422_field_error_and_writes_nothing(): void
    {
        $variationId = $this->variationId();

        $response = $this->putJson("/api/variations/{$variationId}/stock", ['quantity' => 4294967296]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
        $this->assertSame(0, StockLevelModel::count());
    }
}
