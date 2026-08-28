<?php

namespace Tests\Feature;

use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Enums\PriceListStatus;
use EasyCo\Pricing\Persistence\Eloquent\PriceListModel;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EloquentPriceListRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): PriceListRepository
    {
        return app(PriceListRepository::class);
    }

    public function test_save_then_find_by_id_round_trips_all_fields(): void
    {
        $validFrom = new \DateTimeImmutable('2026-01-01 00:00:00');
        $validUntil = new \DateTimeImmutable('2026-03-01 00:00:00');

        $list = PriceList::create(
            name: 'Autumn/Winter 2025',
            mode: PriceListMode::PERCENTAGE_OFF_REGULAR,
            priority: 20,
            validFrom: $validFrom,
            validUntil: $validUntil,
            percentageBasisPoints: 3000,
        );

        $this->repository()->save($list);

        $this->assertNotNull($list->id());

        $reloaded = $this->repository()->findById($list->id());

        $this->assertNotNull($reloaded);
        $this->assertSame($list->id(), $reloaded->id());
        $this->assertSame('Autumn/Winter 2025', $reloaded->name());
        $this->assertSame(PriceListMode::PERCENTAGE_OFF_REGULAR, $reloaded->mode());
        $this->assertSame(20, $reloaded->priority());
        $this->assertEquals($validFrom, $reloaded->validFrom());
        $this->assertEquals($validUntil, $reloaded->validUntil());
        $this->assertSame(3000, $reloaded->percentageBasisPoints());
        $this->assertSame(PriceListStatus::ACTIVE, $reloaded->status());
        $this->assertFalse($reloaded->isSystem());
    }

    public function test_find_by_id_returns_null_for_a_nonexistent_id(): void
    {
        $this->assertNull($this->repository()->findById('999999'));
    }

    public function test_a_new_list_gets_the_universal_scope_signature_automatically(): void
    {
        $list = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);

        $this->repository()->save($list);

        $model = PriceListModel::find($list->id());

        $this->assertSame(PriceListSignature::forUniversalScope()->value(), $model->scope_signature);
    }

    public function test_update_in_place_does_not_create_a_new_row_and_preserves_the_id(): void
    {
        $list = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->repository()->save($list);
        $originalId = $list->id();

        $list->rename('Wholesale (B2B)');
        $this->repository()->save($list);

        $this->assertSame($originalId, $list->id());
        $this->assertSame(1, PriceListModel::count());

        $reloaded = $this->repository()->findById($originalId);
        $this->assertSame('Wholesale (B2B)', $reloaded->name());
    }

    public function test_exists_active_at_priority_true_when_an_active_list_matches(): void
    {
        $list = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->repository()->save($list);

        $this->assertTrue($this->repository()->existsActiveAtPriority(10));
    }

    public function test_exists_active_at_priority_false_when_nothing_matches(): void
    {
        $this->assertFalse($this->repository()->existsActiveAtPriority(999));
    }

    public function test_exists_active_at_priority_false_for_an_inactive_list_at_the_same_priority(): void
    {
        $list = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $list->deactivate();
        $this->repository()->save($list);

        $this->assertFalse($this->repository()->existsActiveAtPriority(10));
    }

    public function test_exists_active_at_priority_respects_excluding_id(): void
    {
        $list = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->repository()->save($list);

        $this->assertFalse($this->repository()->existsActiveAtPriority(10, excludingId: $list->id()));
        $this->assertTrue($this->repository()->existsActiveAtPriority(10, excludingId: 'some-other-id'));
    }
}
