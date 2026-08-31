<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\Exceptions\InsufficientStockException;
use EasyCo\Inventory\Persistence\Eloquent\StockLevelModel;
use EasyCo\Inventory\StockLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EloquentStockLevelRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;

    private function repository(): StockLevelRepository
    {
        return app(StockLevelRepository::class);
    }

    private function variationId(): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    public function test_the_real_variation_id_column_has_a_unique_constraint(): void
    {
        // Confirms the actual constraint this repository's upsert
        // behavior depends on — not just trusting the migration file
        // (CLAUDE.md rule 2/project convention).
        $createTable = DB::select('SHOW CREATE TABLE stock_levels')[0]->{'Create Table'};

        $this->assertStringContainsString('UNIQUE KEY `stock_levels_variation_id_unique`', $createTable);
    }

    public function test_find_by_variation_id_for_a_variation_with_no_row_returns_a_real_zero_quantity_object_not_null(): void
    {
        $variationId = $this->variationId();

        $stockLevel = $this->repository()->findByVariationId($variationId);

        $this->assertInstanceOf(StockLevel::class, $stockLevel);
        $this->assertNull($stockLevel->id());
        $this->assertSame($variationId, $stockLevel->variationId());
        $this->assertSame(0, $stockLevel->quantity());
        $this->assertSame(0, StockLevelModel::count());
    }

    public function test_save_creates_a_row_and_assigns_an_id(): void
    {
        $variationId = $this->variationId();
        $stockLevel = StockLevel::forVariation($variationId, 20);

        $this->repository()->save($stockLevel);

        $this->assertNotNull($stockLevel->id());
        $this->assertSame(1, StockLevelModel::count());

        $reloaded = $this->repository()->findByVariationId($variationId);
        $this->assertSame(20, $reloaded->quantity());
    }

    public function test_saving_twice_for_the_same_variation_updates_in_place_never_creates_a_second_row(): void
    {
        $variationId = $this->variationId();

        $this->repository()->save(StockLevel::forVariation($variationId, 10));
        $this->repository()->save(StockLevel::forVariation($variationId, 30));

        $this->assertSame(1, StockLevelModel::count());
        $this->assertSame(30, $this->repository()->findByVariationId($variationId)->quantity());
    }

    public function test_decrease_happy_path_reduces_quantity_correctly(): void
    {
        $variationId = $this->variationId();
        $this->repository()->save(StockLevel::forVariation($variationId, 10));

        $this->repository()->decrease($variationId, 3);

        $this->assertSame(7, $this->repository()->findByVariationId($variationId)->quantity());
    }

    public function test_decrease_requesting_exactly_the_available_amount_succeeds_and_leaves_zero(): void
    {
        $variationId = $this->variationId();
        $this->repository()->save(StockLevel::forVariation($variationId, 5));

        $this->repository()->decrease($variationId, 5);

        $this->assertSame(0, $this->repository()->findByVariationId($variationId)->quantity());
    }

    public function test_decrease_with_insufficient_stock_throws_and_leaves_quantity_unchanged(): void
    {
        $variationId = $this->variationId();
        $this->repository()->save(StockLevel::forVariation($variationId, 3));

        try {
            $this->repository()->decrease($variationId, 4);
            $this->fail('Expected InsufficientStockException was not thrown.');
        } catch (InsufficientStockException $e) {
            // expected
        }

        $this->assertSame(3, $this->repository()->findByVariationId($variationId)->quantity());
    }

    public function test_decrease_against_a_variation_with_no_row_at_all_throws(): void
    {
        $variationId = $this->variationId();

        $this->expectException(InsufficientStockException::class);
        $this->repository()->decrease($variationId, 1);
    }

    public function test_increase_against_a_variation_with_no_row_at_all_creates_one_with_the_increased_amount(): void
    {
        // The exact case decision #7 exists for: increment() alone is
        // a silent no-op against zero matched rows, it does not
        // insert — this test would pass on a bare increment() call in
        // dev but must exercise the real create-then-increment path.
        $variationId = $this->variationId();
        $this->assertSame(0, StockLevelModel::count());

        $this->repository()->increase($variationId, 15);

        $this->assertSame(1, StockLevelModel::count());
        $this->assertSame(15, $this->repository()->findByVariationId($variationId)->quantity());
    }

    public function test_increase_against_an_existing_row_adds_correctly(): void
    {
        $variationId = $this->variationId();
        $this->repository()->save(StockLevel::forVariation($variationId, 10));

        $this->repository()->increase($variationId, 5);

        $this->assertSame(1, StockLevelModel::count());
        $this->assertSame(15, $this->repository()->findByVariationId($variationId)->quantity());
    }
}
