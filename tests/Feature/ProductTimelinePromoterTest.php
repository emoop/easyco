<?php

namespace Tests\Feature;

use App\Models\ActivityLogModel;
use App\Services\Exceptions\CannotPromoteArchivedProductException;
use App\Services\ProductTimelinePromoter;
use App\Settings\Contracts\SiteSettingsRepository;
use DateTimeImmutable;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * App\Services\ProductTimelinePromoter — every test uses a FIXED,
 * explicit DateTimeImmutable for $at, never a live now(), per the
 * class's own "explicit required parameter, never internal now()"
 * posture (the same convention CheckoutOrchestrator::place() already
 * documents).
 */
class ProductTimelinePromoterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Off by default — same established precedent every other
        // activity-log-asserting test in this codebase already uses.
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
    }

    private function promoter(): ProductTimelinePromoter
    {
        return app(ProductTimelinePromoter::class);
    }

    private function persistedProduct(): Product
    {
        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        app(ProductRepository::class)->save($product);

        return $product;
    }

    /**
     * catalog_products.timeline_at is a TIMESTAMP column with no
     * fractional-second precision — a value round-tripped through
     * save()+reload loses sub-second precision, a real, expected DB
     * characteristic, not a bug.
     */
    private function truncatedToSecond(DateTimeImmutable $at): DateTimeImmutable
    {
        return $at->setTime((int) $at->format('H'), (int) $at->format('i'), (int) $at->format('s'), 0);
    }

    public function test_promote_sets_exactly_the_given_instant(): void
    {
        $product = $this->persistedProduct();
        $clock = $product->createdAt()->modify('+1 day');

        $this->promoter()->promote($product->id(), $clock);

        $reloaded = app(ProductRepository::class)->findById($product->id());
        $this->assertEquals($this->truncatedToSecond($clock), $reloaded->timelineAt());
        $this->assertTrue($reloaded->isPromoted());
    }

    public function test_promote_logs_the_real_old_and_new_timeline_at(): void
    {
        $product = $this->persistedProduct();
        $originalCreatedAt = $product->createdAt();
        $clock = $originalCreatedAt->modify('+1 day');

        $this->promoter()->promote($product->id(), $clock);

        $entry = ActivityLogModel::where('entity_type', 'product')
            ->where('entity_id', $product->id())
            ->where('field', 'timeline_at')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($originalCreatedAt->format(\DATE_ATOM), $entry->old_value);
        $this->assertSame($clock->format(\DATE_ATOM), $entry->new_value);
    }

    public function test_re_promoting_moves_it_again_and_logs_the_new_change(): void
    {
        $product = $this->persistedProduct();
        $firstClock = $product->createdAt()->modify('+1 day');
        $secondClock = $product->createdAt()->modify('+5 days');

        $this->promoter()->promote($product->id(), $firstClock);
        $this->promoter()->promote($product->id(), $secondClock);

        $reloaded = app(ProductRepository::class)->findById($product->id());
        $this->assertEquals($this->truncatedToSecond($secondClock), $reloaded->timelineAt());

        $entries = ActivityLogModel::where('entity_type', 'product')
            ->where('entity_id', $product->id())
            ->where('field', 'timeline_at')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $entries);
        $this->assertSame($product->createdAt()->format(\DATE_ATOM), $entries[0]->old_value);
        $this->assertSame($firstClock->format(\DATE_ATOM), $entries[0]->new_value);
        $this->assertSame($firstClock->format(\DATE_ATOM), $entries[1]->old_value);
        $this->assertSame($secondClock->format(\DATE_ATOM), $entries[1]->new_value);
    }

    public function test_unpromote_restores_created_at_and_logs_it(): void
    {
        $product = $this->persistedProduct();
        $originalCreatedAt = $product->createdAt();
        $this->promoter()->promote($product->id(), $originalCreatedAt->modify('+5 days'));

        $this->promoter()->unpromote($product->id());

        $reloaded = app(ProductRepository::class)->findById($product->id());
        // catalog_products.created_at/timeline_at are TIMESTAMP columns
        // with no fractional-second precision — reloaded->createdAt()
        // is truncated to the whole second, while the in-memory
        // $originalCreatedAt captured above is microsecond-precise.
        $this->assertEquals($reloaded->createdAt(), $reloaded->timelineAt());
        $this->assertFalse($reloaded->isPromoted());

        $entries = ActivityLogModel::where('entity_type', 'product')
            ->where('entity_id', $product->id())
            ->where('field', 'timeline_at')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $entries, 'one entry for promote, one for unpromote');
        $lastEntry = $entries->last();
        $this->assertSame($originalCreatedAt->format(\DATE_ATOM), $lastEntry->new_value);
    }

    public function test_unpromote_on_a_non_promoted_product_writes_nothing_and_logs_nothing(): void
    {
        $product = $this->persistedProduct();
        $originalUpdatedAt = \EasyCo\Catalog\Persistence\Eloquent\ProductModel::find($product->id())->updated_at;

        $this->promoter()->unpromote($product->id());

        $row = \EasyCo\Catalog\Persistence\Eloquent\ProductModel::find($product->id());
        $this->assertTrue($originalUpdatedAt->equalTo($row->updated_at), 'no save() must have happened at all');

        $this->assertFalse(
            ActivityLogModel::where('entity_type', 'product')
                ->where('entity_id', $product->id())
                ->where('field', 'timeline_at')
                ->exists(),
            'unpromote() on an already-un-promoted product must write no log entry'
        );
    }

    public function test_promoting_an_archived_product_is_refused_and_nothing_is_written(): void
    {
        $product = $this->persistedProduct();
        $product->archive();
        app(ProductRepository::class)->save($product);

        $this->expectException(CannotPromoteArchivedProductException::class);

        try {
            $this->promoter()->promote($product->id(), new DateTimeImmutable('2026-06-15 12:00:00'));
        } finally {
            $this->assertFalse(
                ActivityLogModel::where('entity_type', 'product')
                    ->where('entity_id', $product->id())
                    ->where('field', 'timeline_at')
                    ->exists()
            );
        }
    }

    public function test_promoting_a_nonexistent_product_throws(): void
    {
        $this->expectException(RuntimeException::class);

        $this->promoter()->promote('999999', new DateTimeImmutable('2026-06-15 12:00:00'));
    }

    public function test_unpromoting_a_nonexistent_product_throws(): void
    {
        $this->expectException(RuntimeException::class);

        $this->promoter()->unpromote('999999');
    }
}
