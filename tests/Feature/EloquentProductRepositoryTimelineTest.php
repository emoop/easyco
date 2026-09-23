<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EloquentProductRepository's own round-trip of Product::createdAt()/
 * timelineAt() — real MySQL, real catalog_products.timeline_at column
 * (2026_09_24_000001_add_timeline_at_to_catalog_products_table.php).
 */
class EloquentProductRepositoryTimelineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * catalog_products.created_at/timeline_at are TIMESTAMP columns with
     * NO fractional-second precision ($table->timestamps() default) —
     * a value round-tripped through storage loses sub-second precision,
     * a real, expected DB characteristic, not a bug. Truncated to
     * whole-second precision before comparing an in-memory (microsecond-
     * precise) instant against a reloaded one.
     */
    private function truncatedToSecond(DateTimeImmutable $at): DateTimeImmutable
    {
        return $at->setTime((int) $at->format('H'), (int) $at->format('i'), (int) $at->format('s'), 0);
    }

    public function test_a_new_products_timeline_at_is_persisted_equal_to_its_created_at(): void
    {
        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        app(ProductRepository::class)->save($product);

        $row = ProductModel::find($product->id());

        $this->assertNotNull($row->created_at);
        $this->assertNotNull($row->timeline_at);
        $this->assertTrue($row->created_at->equalTo($row->timeline_at), 'timeline_at must equal created_at for a brand-new row');

        $reloaded = app(ProductRepository::class)->findById($product->id());
        $this->assertEquals($reloaded->createdAt(), $reloaded->timelineAt());
        $this->assertFalse($reloaded->isPromoted());
    }

    public function test_a_promoted_products_timeline_at_round_trips_correctly_and_created_at_is_untouched(): void
    {
        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        app(ProductRepository::class)->save($product);

        $originalCreatedAt = $product->createdAt();
        $promotedAt = $originalCreatedAt->modify('+3 days');

        $product->promote($promotedAt);
        app(ProductRepository::class)->save($product);

        $reloaded = app(ProductRepository::class)->findById($product->id());

        $this->assertEquals($this->truncatedToSecond($promotedAt), $reloaded->timelineAt());
        $this->assertEquals($this->truncatedToSecond($originalCreatedAt), $reloaded->createdAt(), 'created_at must be completely untouched by a promote()+save()');
        $this->assertTrue($reloaded->isPromoted());
    }

    public function test_unpromoting_and_saving_restores_timeline_at_in_storage(): void
    {
        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        app(ProductRepository::class)->save($product);

        $product->promote($product->createdAt()->modify('+3 days'));
        app(ProductRepository::class)->save($product);

        $product->unpromote();
        app(ProductRepository::class)->save($product);

        $reloaded = app(ProductRepository::class)->findById($product->id());
        $this->assertEquals($reloaded->createdAt(), $reloaded->timelineAt());
        $this->assertFalse($reloaded->isPromoted());
    }

    public function test_editing_an_unrelated_field_on_an_already_persisted_product_does_not_move_its_timeline(): void
    {
        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        app(ProductRepository::class)->save($product);
        $originalTimelineAt = $product->timelineAt();

        $reloaded = app(ProductRepository::class)->findById($product->id());
        $reloaded->rename('Air Max 2');
        app(ProductRepository::class)->save($reloaded);

        $reloadedAgain = app(ProductRepository::class)->findById($product->id());
        $this->assertEquals($this->truncatedToSecond($originalTimelineAt), $reloadedAgain->timelineAt());
        $this->assertEquals($this->truncatedToSecond($originalTimelineAt), $reloadedAgain->createdAt());
    }
}
