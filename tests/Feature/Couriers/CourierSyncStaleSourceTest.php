<?php

namespace Tests\Feature\Couriers;

use App\Couriers\CourierSyncRun;
use App\Filament\Pages\NeedsAttention;
use App\NeedsAttention\CourierSyncStaleSource;
use App\NeedsAttention\NeedsAttentionSource;
use App\Privacy\OpenMoneyOrders;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\EnablesBoxNow;
use Tests\Feature\Mail\ActsAsMailStaff;
use Tests\TestCase;

/** courier-integration-design.md §3.4, §6.8: one row per ENABLED courier, after 2 failed runs or 48 h without a good one. */
class CourierSyncStaleSourceTest extends TestCase
{
    use ActsAsMailStaff;
    use EnablesBoxNow;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-26 12:00:00');
    }

    private function source(): CourierSyncStaleSource
    {
        return app(CourierSyncStaleSource::class);
    }

    private function recordRun(string $outcome, string $startedAt, ?string $error = null): void
    {
        CourierSyncRun::create([
            'carrier_code' => 'boxnow', 'trigger' => 'schedule', 'started_at' => $startedAt, 'finished_at' => $startedAt,
            'outcome' => $outcome, 'error_code' => $error,
        ]);
    }

    public function test_nothing_is_listed_for_a_courier_with_no_history_or_one_that_is_disabled(): void
    {
        $this->assertSame(0, $this->source()->count(), 'disabled');

        $this->recordRun('failed', '2026-11-25 03:40:00', 'timeout');
        $this->recordRun('failed', '2026-11-26 03:40:00', 'timeout');
        $this->assertSame(0, $this->source()->count(), 'a disabled courier is never stale: it is not synced');

        $this->enableBoxNow();
        $this->assertSame(1, $this->source()->count());
    }

    public function test_a_just_enabled_courier_with_no_runs_yet_is_not_listed(): void
    {
        $this->enableBoxNow();

        $this->assertSame(0, $this->source()->count());

        app(SiteSettingsRepository::class)->set('couriers.boxnow.enabled_at', '2026-11-26 09:00:00');
        $this->assertSame(0, $this->source()->count());
    }

    public function test_two_failed_runs_in_a_row_list_the_courier_once(): void
    {
        $this->enableBoxNow();
        $this->recordRun('ok', '2026-11-25 03:40:00');
        $this->recordRun('failed', '2026-11-26 03:40:00', 'timeout');
        $this->assertSame(0, $this->source()->count(), 'one failure is not enough');

        $this->recordRun('failed', '2026-11-26 11:00:00', 'courier_unavailable');

        $this->assertSame(1, $this->source()->count());
        $item = $this->source()->page(1, 25)[0];
        $this->assertNull($item->orderId, 'not about an order');
        $this->assertNull($item->amount);
        $this->assertSame('BOX NOW', $item->subject);
        $this->assertStringContainsString('BOX NOW', $item->fact);
        $this->assertStringContainsString('courier_unavailable', $item->fact);
        $this->assertSame(1, $item->ageDays, 'counted from the last good run, 25 Nov');
        $this->assertSame('2026-11-25', $item->startedOn);
    }

    public function test_many_failed_nights_are_still_one_row_and_a_refused_shrink_counts_as_a_failure(): void
    {
        $this->enableBoxNow();

        foreach (range(1, 6) as $day) {
            $this->recordRun($day % 2 === 0 ? 'failed' : 'refused_shrink', "2026-11-2{$day} 03:40:00", $day % 2 === 0 ? 'timeout' : 'shrink');
        }

        $this->assertSame(1, $this->source()->count());
        $this->assertCount(1, $this->source()->page(1, 25));
        $this->assertSame([], $this->source()->page(2, 25), 'a page past the end is empty');
    }

    public function test_a_good_run_after_failures_clears_it(): void
    {
        $this->enableBoxNow();
        $this->recordRun('failed', '2026-11-25 03:40:00', 'timeout');
        $this->recordRun('failed', '2026-11-26 03:40:00', 'timeout');
        $this->assertSame(1, $this->source()->count());

        $this->recordRun('partial', '2026-11-26 11:00:00');

        $this->assertSame(0, $this->source()->count());
    }

    public function test_a_last_success_older_than_48_hours_lists_it_and_47_hours_does_not(): void
    {
        $this->enableBoxNow();
        $this->recordRun('ok', '2026-11-24 13:00:00'); // 47 h ago
        $this->assertSame(0, $this->source()->count());

        CourierSyncRun::query()->delete();
        $this->recordRun('ok', '2026-11-24 11:00:00'); // 49 h ago
        $this->assertSame(1, $this->source()->count());
        $this->assertStringContainsString('48', $this->source()->page(1, 25)[0]->fact);
    }

    public function test_a_courier_that_never_succeeded_is_listed_once_it_has_been_enabled_for_48_hours(): void
    {
        $this->enableBoxNow();
        $settings = app(SiteSettingsRepository::class);

        $settings->set('couriers.boxnow.enabled_at', '2026-11-26 00:00:00');
        $this->recordRun('failed', '2026-11-26 03:40:00', 'timeout');
        $this->assertSame(0, $this->source()->count(), 'enabled 12 hours ago, one failure');

        $settings->set('couriers.boxnow.enabled_at', '2026-11-20 00:00:00');
        $this->assertSame(1, $this->source()->count());
    }

    public function test_the_source_is_on_the_needs_attention_page_without_a_link_and_holds_no_order_back(): void
    {
        $this->enableBoxNow();
        $this->recordRun('failed', '2026-11-25 03:40:00', 'timeout');
        $this->recordRun('failed', '2026-11-26 03:40:00', 'timeout');
        $this->actAsPanelAdministrator();

        $keys = array_map(static fn (NeedsAttentionSource $s): string => $s->key(), iterator_to_array(app()->tagged(NeedsAttentionSource::TAG), false));
        $this->assertSame(['owed_refund', 'receipt_mismatch', 'payment_step_unfinished', 'courier_sync_stale'], $keys);

        $html = $this->get(NeedsAttention::getUrl())->assertOk()->getContent();
        $this->assertStringContainsString(__('needs_attention.sources.courier_sync_stale.label'), $html);
        $this->assertStringContainsString('BOX NOW', $html);

        $this->assertSame([], app(OpenMoneyOrders::class)->ids(), 'a courier row names no order');
    }
}
