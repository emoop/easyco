<?php

namespace Tests\Feature\Couriers;

use App\Console\Commands\SyncCouriers;
use App\Couriers\CourierSyncRun;
use App\Couriers\Events\PickupPointsSynced;
use App\Couriers\PickupPointSyncer;
use App\Couriers\SyncCourierPointsJob;
use App\Services\Exceptions\UnknownShippingCarrierException;
use App\Services\ShippingProviderResolver;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\CourierBoxNow\BoxNowCatalogue;
use EasyCo\CourierBoxNow\Tests\FakeCourierHttp;
use EasyCo\Shipping\Carrier\CourierCredentials;
use EasyCo\Shipping\Carrier\CourierResponse;
use EasyCo\Shipping\Carrier\PickupPoint;
use EasyCo\Shipping\Carrier\PickupPointBatch;
use EasyCo\Shipping\Carrier\PickupPointKind;
use EasyCo\Shipping\Contracts\CourierHttp;
use EasyCo\Shipping\Contracts\PickupPointCatalogue;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\EnablesBoxNow;
use Tests\TestCase;

/** courier-integration-design.md §3.4-3.5: the sync, with a fake CourierHttp and the fixture: no network. */
class PickupPointSyncTest extends TestCase
{
    use EnablesBoxNow;
    use RefreshDatabase;

    private FakeCourierHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeCourierHttp;
        $this->app->instance(CourierHttp::class, $this->http);
    }

    private function sync(string $trigger = 'cli'): ?CourierSyncRun
    {
        return app(PickupPointSyncer::class)->run('boxnow', $trigger);
    }

    private function answer(string $body, int $status = 200): void
    {
        $this->http->answer(new CourierResponse($status, $body));
    }

    /** @return string a locker list of $count valid lockers, ids 1..$count (optionally only those in $ids) */
    private function list(int $count, ?array $ids = null, string $titlePrefix = 'Locker'): string
    {
        $items = [];

        foreach ($ids ?? range(1, $count) as $id) {
            $items[] = ['id' => (string) $id, 'type' => 'apm', 'title' => "{$titlePrefix} {$id}", 'addressLine1' => "Test Street {$id}", 'addressLine2' => '1000 Sofia', 'lat' => '42.7', 'lng' => '23.3'];
        }

        return (string) json_encode(['data' => $items]);
    }

    private function active(): int
    {
        return DB::table('shipping_pickup_points')->where('carrier_code', 'boxnow')->where('is_active', 1)->count();
    }

    public function test_a_first_sync_into_an_empty_table_stores_the_valid_points_and_counts_the_malformed(): void
    {
        $this->enableBoxNow();
        $this->answer($this->boxNowFixture());

        $run = $this->sync('manual');

        $this->assertSame('partial', $run->outcome, 'a run that skipped something says so');
        $this->assertSame(3, $run->seen);
        $this->assertSame(3, $run->new_count);
        $this->assertSame(0, $run->changed);
        $this->assertSame(0, $run->deactivated);
        $this->assertSame(4, $run->skipped);
        $this->assertSame(0, $run->unmapped);
        $this->assertNull($run->error_code);
        $this->assertSame('manual', $run->trigger);
        $this->assertNotNull($run->finished_at);

        $this->assertSame(3, $this->active());
        $row = DB::table('shipping_pickup_points')->where('external_ref', '2001')->first();
        $this->assertSame('locker', $row->kind);
        $this->assertSame('apm', $row->courier_type);
        $this->assertSame('Sofia', $row->settlement_name);
        $this->assertSame('1000', $row->postcode);
        $this->assertNull($row->settlement_id, 'COUR-1b maps settlements');
        $this->assertNotNull($row->first_seen_at);
        $this->assertNull($row->deactivated_at);
        $this->assertEquals(42.6977, (float) $row->latitude);
    }

    public function test_an_identical_second_sync_changes_nothing(): void
    {
        $this->enableBoxNow();
        $this->answer($this->list(10));
        $this->answer($this->list(10));

        $this->sync();
        $before = DB::table('shipping_pickup_points')->orderBy('id')->get();
        $run = $this->sync();

        $this->assertSame('ok', $run->outcome);
        $this->assertSame([10, 0, 0, 0], [$run->seen, $run->new_count, $run->changed, $run->deactivated]);
        $this->assertSame(10, DB::table('shipping_pickup_points')->count(), 'no duplicate rows');

        $after = DB::table('shipping_pickup_points')->orderBy('id')->get();
        foreach ($before as $i => $row) {
            $this->assertSame($row->first_seen_at, $after[$i]->first_seen_at);
            $this->assertSame($row->name, $after[$i]->name);
            $this->assertNotSame($row->synced_at, $after[$i]->synced_at, 'seen again, so stamped again');
        }
    }

    public function test_a_changed_point_is_updated_and_keeps_its_first_seen_time(): void
    {
        $this->enableBoxNow();
        $this->answer($this->list(10));
        $this->answer($this->list(10, null, 'Renamed'));

        $this->sync();
        $first = DB::table('shipping_pickup_points')->where('external_ref', '3')->first();
        $run = $this->sync();

        $this->assertSame([0, 10], [$run->new_count, $run->changed]);
        $row = DB::table('shipping_pickup_points')->where('external_ref', '3')->first();
        $this->assertSame('Renamed 3', $row->name);
        $this->assertSame($first->first_seen_at, $row->first_seen_at);
        $this->assertSame($first->id, $row->id);
    }

    public function test_an_unseen_point_is_deactivated_never_deleted_and_comes_back_when_listed_again(): void
    {
        $this->enableBoxNow();
        $this->answer($this->list(10));
        $this->answer($this->list(0, [1, 2, 3, 4, 5, 6, 7, 8, 9]));
        $this->answer($this->list(10));

        $this->sync();
        $run = $this->sync();

        $this->assertSame(1, $run->deactivated);
        $this->assertSame('ok', $run->outcome);
        $gone = DB::table('shipping_pickup_points')->where('external_ref', '10')->first();
        $this->assertNotNull($gone, 'the row is kept (history)');
        $this->assertSame(0, (int) $gone->is_active);
        $this->assertNotNull($gone->deactivated_at);
        $this->assertSame(9, $this->active());

        $run = $this->sync();

        $back = DB::table('shipping_pickup_points')->where('external_ref', '10')->first();
        $this->assertSame(1, (int) $back->is_active);
        $this->assertNull($back->deactivated_at);
        $this->assertSame($gone->first_seen_at, $back->first_seen_at);
        $this->assertSame([0, 1, 0], [$run->new_count, $run->changed, $run->deactivated], 'a reactivation is a change');
    }

    public function test_the_circuit_breaker_refuses_a_list_under_80_percent_and_deactivates_nothing(): void
    {
        $this->enableBoxNow();
        $this->answer($this->list(10));
        $this->answer($this->list(0, [1, 2, 3, 4, 5, 6, 7]));   // 70 %
        $this->answer($this->list(0, [1, 2, 3, 4, 5, 6, 7, 8])); // exactly 80 %: allowed

        $this->sync();
        $before = DB::table('shipping_pickup_points')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $run = $this->sync();

        $this->assertSame('refused_shrink', $run->outcome);
        $this->assertSame('shrink', $run->error_code);
        $this->assertSame([7, 0, 0, 0], [$run->seen, $run->new_count, $run->changed, $run->deactivated]);
        $this->assertSame($before, DB::table('shipping_pickup_points')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'nothing was touched');
        $this->assertSame(10, $this->active());

        $run = $this->sync();

        $this->assertSame('ok', $run->outcome);
        $this->assertSame(2, $run->deactivated);
        $this->assertSame(8, $this->active());
    }

    public function test_an_empty_answer_after_a_filled_table_is_refused_but_an_empty_first_sync_is_allowed(): void
    {
        $this->enableBoxNow();
        $this->answer('{"data": []}');

        $first = $this->sync();
        $this->assertSame('ok', $first->outcome, 'nothing to compare with');
        $this->assertSame(0, DB::table('shipping_pickup_points')->count());

        $this->answer($this->list(10));
        $this->answer('{"data": []}');
        $this->sync();
        $run = $this->sync();

        $this->assertSame('refused_shrink', $run->outcome);
        $this->assertSame(10, $this->active(), 'a courier that returns nothing never empties the checkout');
    }

    public function test_a_failed_download_is_recorded_with_a_closed_code_and_keeps_the_last_good_list(): void
    {
        $this->enableBoxNow();
        $this->answer($this->list(10));
        $this->answer('secret upstream text', 503);
        $this->answer('this is not a list');

        $this->sync();

        $run = $this->sync();
        $this->assertSame('failed', $run->outcome);
        $this->assertSame('courier_unavailable', $run->error_code);

        $run = $this->sync();
        $this->assertSame('failed', $run->outcome);
        $this->assertSame('invalid_response', $run->error_code);

        $this->assertSame(10, $this->active());

        foreach (CourierSyncRun::all() as $row) {
            $this->assertStringNotContainsString('secret upstream text', json_encode($row->getAttributes()));
            $this->assertStringNotContainsString(self::FAKE_SECRET, json_encode($row->getAttributes()));
            $this->assertStringNotContainsString(self::FAKE_API_HOST, json_encode($row->getAttributes()));
        }
    }

    public function test_more_than_one_chunk_of_points_is_stored_in_full(): void
    {
        $this->enableBoxNow();
        $this->answer($this->list(1203));

        $run = $this->sync();

        $this->assertSame(1203, $run->new_count);
        $this->assertSame(1203, $this->active());
        $this->assertSame(500, PickupPointSyncer::CHUNK);
    }

    public function test_a_reference_the_table_cannot_hold_and_a_repeated_reference_are_skipped_and_counted(): void
    {
        $this->enableBoxNow();
        $point = fn (string $ref, string $name = 'Locker') => new PickupPoint('boxnow', $ref, $name, 'BG', 'Sofia', 'Street 1', PickupPointKind::LOCKER);
        $this->app->instance('shipping.carrier.boxnow.pickup', new class([$point('A1'), $point('A1', 'Twice'), $point(str_repeat('x', 65)), $point('A2')]) implements PickupPointCatalogue
        {
            public function __construct(private array $points) {}

            public function allPoints(CourierCredentials $credentials, string $countryCode): PickupPointBatch
            {
                return new PickupPointBatch($this->points, 1);
            }

            public function settlements(CourierCredentials $credentials, string $countryCode): array
            {
                return [];
            }
        });

        $run = $this->sync();

        $this->assertSame(2, $run->seen);
        $this->assertSame(3, $run->skipped, 'one the catalogue counted, one repeat, one too long');
        $this->assertSame(['A1', 'A2'], DB::table('shipping_pickup_points')->orderBy('external_ref')->pluck('external_ref')->all());
        $this->assertSame('Locker', DB::table('shipping_pickup_points')->where('external_ref', 'A1')->value('name'), 'the first one wins');
    }

    public function test_a_sync_announces_itself_only_when_it_changed_the_table(): void
    {
        $this->enableBoxNow();
        Event::fake([PickupPointsSynced::class]);
        $this->answer($this->list(10));
        $this->answer($this->list(0, [1, 2]));
        $this->answer('x', 500);

        $this->sync();
        Event::assertDispatchedTimes(PickupPointsSynced::class, 1);

        $this->sync(); // refused_shrink
        $this->sync(); // failed
        Event::assertDispatchedTimes(PickupPointsSynced::class, 1);
    }

    public function test_a_disabled_courier_is_never_called_and_never_recorded(): void
    {
        // Installed, never enabled: the fake has no answer queued, so any call would fail the test loudly.
        $this->assertNull($this->sync());
        $this->assertSame([], $this->http->calls);
        $this->assertSame(0, CourierSyncRun::count());

        $this->enableBoxNow();
        $this->app->make(SiteSettingsRepository::class)->forget('couriers.boxnow.enabled');
        $this->assertNull($this->sync());
        $this->assertSame([], $this->http->calls);
    }

    public function test_a_disabled_courier_is_unknown_to_the_resolver_and_an_enabled_one_resolves(): void
    {
        $resolver = app(ShippingProviderResolver::class);

        try {
            $resolver->pickupPointCatalogue('boxnow');
            $this->fail('an installed but disabled courier must be unreachable');
        } catch (UnknownShippingCarrierException) {
            $this->addToAssertionCount(1);
        }

        $this->enableBoxNow();

        $this->assertInstanceOf(BoxNowCatalogue::class, $resolver->pickupPointCatalogue('boxnow'));
    }

    public function test_the_command_queues_only_enabled_couriers_and_refuses_a_disabled_one(): void
    {
        Queue::fake();

        $this->artisan('couriers:sync')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->artisan('couriers:sync boxnow')->assertFailed();
        $this->artisan('couriers:sync nobody')->assertFailed();
        Queue::assertNothingPushed();

        $this->enableBoxNow();

        $this->artisan('couriers:sync')->assertSuccessful();
        Queue::assertPushed(SyncCourierPointsJob::class, fn (SyncCourierPointsJob $job): bool => $job->carrierCode === 'boxnow' && $job->trigger === 'schedule');
        Queue::assertPushedTimes(SyncCourierPointsJob::class, 1);
    }

    public function test_the_job_carries_only_the_carrier_code_and_runs_the_sync(): void
    {
        $this->enableBoxNow();
        $this->answer($this->list(10));

        $job = new SyncCourierPointsJob('boxnow', 'manual');
        $this->assertStringNotContainsString(self::FAKE_SECRET, serialize($job));
        $this->assertSame(['carrierCode', 'trigger'], array_values(array_intersect(array_keys(get_object_vars($job)), ['carrierCode', 'trigger', 'secret', 'credentials'])));

        dispatch_sync($job);

        $this->assertSame(10, $this->active());
        $this->assertSame('manual', CourierSyncRun::first()->trigger);
    }

    public function test_the_command_can_run_the_sync_in_process(): void
    {
        $this->enableBoxNow();
        $this->answer($this->list(10));

        $this->artisan('couriers:sync boxnow --now')->assertSuccessful();

        $this->assertSame(10, $this->active());
        $this->assertSame('cli', CourierSyncRun::first()->trigger);
        $this->assertTrue(class_exists(SyncCouriers::class));
    }

    public function test_the_sync_is_scheduled_nightly_at_0340_without_overlapping(): void
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn ($event): bool => str_contains((string) $event->command, 'couriers:sync'),
        ));

        $this->assertCount(1, $events);
        $this->assertSame('40 3 * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertStringNotContainsString('boxnow', (string) $events[0]->command, 'one entry for all enabled couriers; a disabled one is simply not queued by it');
    }
}
