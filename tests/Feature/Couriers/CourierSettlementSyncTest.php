<?php

namespace Tests\Feature\Couriers;

use App\Couriers\CourierDirectory;
use App\Couriers\CourierSettings;
use App\Couriers\CourierSyncRun;
use App\Couriers\PickupPointSyncer;
use App\Couriers\Settlements\SettlementBuilder;
use App\Couriers\Settlements\SettlementLinker;
use App\Couriers\Settlements\SettlementReport;
use App\Couriers\Settlements\SettlementSyncer;
use App\Couriers\SyncCourierPointsJob;
use App\Filament\Pages\Settings\CouriersSettings;
use App\Services\ShippingProviderResolver;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\CourierBoxNow\BoxNowDescriptor;
use EasyCo\CourierBoxNow\Tests\FakeCourierHttp;
use EasyCo\Shipping\Carrier\CourierEnvironment;
use EasyCo\Shipping\Carrier\CourierResponse;
use EasyCo\Shipping\Contracts\CourierHttp;
use EasyCo\Shipping\Exceptions\CourierHttpException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\EnablesBoxNow;
use Tests\Feature\Mail\ActsAsMailStaff;
use Tests\TestCase;

/** COUR-1b: the Econt and Speedy syncs end to end (fake CourierHttp, fixtures, fake credentials), the linking and the first-enable run. */
class CourierSettlementSyncTest extends TestCase
{
    use ActsAsMailStaff;
    use EnablesBoxNow;
    use RefreshDatabase;

    private FakeCourierHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeCourierHttp;
        $this->app->instance(CourierHttp::class, $this->http);
    }

    private function ok(string $body): void
    {
        $this->http->answer(new CourierResponse(200, $body));
    }

    private function settlementOf(string $carrier, string $ref): ?string
    {
        return DB::table('shipping_pickup_points as p')
            ->join('shipping_settlements as s', 's.id', '=', 'p.settlement_id')
            ->where('p.carrier_code', $carrier)->where('p.external_ref', $ref)
            ->value('s.name');
    }

    public function test_econt_settlements_are_built_first_and_its_points_are_linked_through_the_city_id(): void
    {
        $this->enableCourier('econt');
        $this->ok($this->courierFixture('CourierEcont', 'cities.sample.json'));
        $this->ok($this->courierFixture('CourierEcont', 'offices.sample.json'));

        $run = app(PickupPointSyncer::class)->run('econt', 'cli');

        // The city list is asked first, then the office list; both on the TEST host.
        $this->assertSame(['/ee/services/Nomenclatures/NomenclaturesService.getCities.json', '/ee/services/Nomenclatures/NomenclaturesService.getOffices.json'], array_column($this->http->calls, 'path'));
        $this->assertSame('demo.econt.com', $this->http->calls[0]['host']);

        $this->assertSame(['ok', 3, 3, 0], [$run->outcome, $run->seen, $run->new_count, $run->unmapped]);

        // Cities: София (1000, rule a), Пловдив (4000, rule a), Костинброд (2230, rule a); the two broken ones were left out.
        $ids = DB::table('shipping_settlements')->whereNotNull('econt_city_id')->orderBy('econt_city_id')->pluck('name', 'econt_city_id')->all();
        $this->assertSame([25 => 'Пловдив', 41 => 'София', 700 => 'Костинброд'], $ids);
        $this->assertSame(0, DB::table('shipping_settlements')->where('source', 'courier')->count());

        $this->assertSame('София', $this->settlementOf('econt', '1101'));
        $this->assertSame('София', $this->settlementOf('econt', '9001'), 'an automatic station of the same city');
        $this->assertSame('Пловдив', $this->settlementOf('econt', '4001'));

        $point = DB::table('shipping_pickup_points')->where('external_ref', '9001')->first();
        $this->assertSame(['locker', 'aps', '9001', '41'], [$point->kind, $point->courier_type, $point->external_id, $point->courier_settlement_ref]);
    }

    public function test_a_point_whose_courier_city_has_no_settlement_stays_listed_and_is_counted_unmapped(): void
    {
        $this->enableCourier('econt');
        $this->ok($this->courierFixture('CourierEcont', 'cities.sample.json'));
        // The offices name a city (id 999) the city list does not know.
        $offices = json_decode($this->courierFixture('CourierEcont', 'offices.sample.json'), true);
        $offices['offices'][2]['address']['city']['id'] = 999;
        $this->ok((string) json_encode($offices));

        $run = app(PickupPointSyncer::class)->run('econt', 'cli');

        $this->assertSame(1, $run->unmapped);
        $orphan = DB::table('shipping_pickup_points')->where('external_ref', '4001')->first();
        $this->assertNull($orphan->settlement_id);
        $this->assertSame('Пловдив', $orphan->settlement_name, 'still selectable under the courier\'s own name');
        $this->assertSame(1, (int) $orphan->is_active);
        $this->assertSame(1, app(SettlementReport::class)->for('econt')['points_unmapped']);

        // The city list changes: the office is mapped on the next link, with no new points sync.
        $this->ok('{"cities": [{"id": 41, "name": "София", "postCode": "1000"}, {"id": 700, "name": "Костинброд", "postCode": "2230"}, {"id": 999, "name": "Пловдив", "postCode": "4000"}]}');
        app(SettlementSyncer::class)->run('econt');
        $this->assertSame(0, app(SettlementLinker::class)->link('econt'), 'the Sofia offices stay mapped, the Plovdiv one is now too');
        $this->assertSame('Пловдив', $this->settlementOf('econt', '4001'));
    }

    public function test_speedy_sites_and_offices_map_through_the_site_id_and_never_leak_the_credentials(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->message.' '.json_encode($e->context);
        });

        $this->enableCourier('speedy', 'fake-speedy-user', 'fake-speedy-pass');
        $this->ok($this->courierFixture('CourierSpeedy', 'sites.sample.csv'));
        $this->ok($this->courierFixture('CourierSpeedy', 'offices.sample.json'));

        $run = app(PickupPointSyncer::class)->run('speedy', 'cli');

        $this->assertSame(['partial', 2, 1, 0], [$run->outcome, $run->seen, $run->skipped, $run->unmapped]);
        $this->assertSame(['/v1/location/site/csv/100', '/v1/location/office'], array_column($this->http->calls, 'path'));
        $this->assertSame('fake-speedy-user', $this->http->calls[0]['body']['userName'], 'the credentials travel in the body, to the client only');

        $this->assertSame([10001 => 'Бов', 56784 => 'Пловдив', 68134 => 'София'], DB::table('shipping_settlements')->whereNotNull('speedy_site_id')->orderBy('speedy_site_id')->pluck('name', 'speedy_site_id')->all());
        $this->assertSame('София', $this->settlementOf('speedy', '100'));
        $this->assertSame('Пловдив', $this->settlementOf('speedy', '7001'));

        $apt = DB::table('shipping_pickup_points')->where('external_ref', '7001')->first();
        $this->assertSame(['locker', 'APT', '56784'], [$apt->kind, $apt->courier_type, $apt->courier_settlement_ref]);

        $everything = implode("\n", $logged)."\n".json_encode(CourierSyncRun::all()->toArray())."\n".json_encode(DB::table('shipping_settlements')->get())."\n".json_encode(DB::table('shipping_pickup_points')->get());
        $this->assertStringNotContainsString('fake-speedy-user', $everything);
        $this->assertStringNotContainsString('fake-speedy-pass', $everything);
    }

    public function test_a_failed_speedy_call_records_a_closed_code_and_never_the_credentials(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->message.' '.json_encode($e->context);
        });

        $this->enableCourier('speedy', 'fake-speedy-user', 'fake-speedy-pass');
        $this->http->answer(new CourierResponse(401, 'fake-speedy-user is not allowed'));             // sites
        $this->http->answer(new CourierResponse(200, '{"error":{"message":"fake-speedy-pass"}}'));     // offices

        $run = app(PickupPointSyncer::class)->run('speedy', 'cli');

        $this->assertSame(['failed', 'auth_failed'], [$run->outcome, $run->error_code]);
        $this->assertSame(0, DB::table('shipping_pickup_points')->count());
        $this->assertStringNotContainsString('fake-speedy', implode("\n", $logged).json_encode(CourierSyncRun::all()->toArray()));
    }

    public function test_boxnow_lockers_map_through_postcode_and_name_and_unknown_ones_are_counted(): void
    {
        $this->enableBoxNow();
        $items = json_decode($this->boxNowFixture(), true)['data'];
        $items[] = ['id' => '90', 'type' => 'apm', 'name' => 'Odd', 'addressLine1' => 'x', 'addressLine2' => 'Несъществуващо', 'postalCode' => '9999'];
        $items[] = ['id' => '91', 'type' => 'apm', 'name' => 'Lozen locker', 'addressLine1' => 'x', 'addressLine2' => 'Лозен', 'postalCode' => '4489']; // Lozen, Pazardzhik: the postcode names the region
        $items[] = ['id' => '92', 'type' => 'apm', 'name' => 'Lozen no postcode', 'addressLine1' => 'x', 'addressLine2' => 'Лозен', 'postalCode' => '']; // five Lozens: ambiguous
        $this->ok((string) json_encode(['data' => $items]));

        $run = app(PickupPointSyncer::class)->run('boxnow', 'cli');

        $this->assertSame(2, $run->unmapped, 'the unknown name and the ambiguous Lozen');
        $this->assertSame('Пловдив', $this->settlementOf('boxnow', '17'));
        $this->assertSame('Лозен', $this->settlementOf('boxnow', '91'));
        $this->assertSame('PAZ', DB::table('shipping_pickup_points as p')->join('shipping_settlements as s', 's.id', '=', 'p.settlement_id')->where('p.external_ref', '91')->value('s.region_code'));
        $this->assertNull(DB::table('shipping_pickup_points')->where('external_ref', '92')->value('settlement_id'));
        $this->assertSame(1, (int) DB::table('shipping_pickup_points')->where('external_ref', '90')->value('is_active'), 'unmapped points stay selectable');
    }

    public function test_settlements_are_built_once_on_the_first_sync_and_again_only_when_asked(): void
    {
        $this->enableCourier('econt');
        $syncer = app(SettlementSyncer::class);
        $this->assertFalse($syncer->hasRun('econt'));

        $this->ok('{"cities": [{"id": 41, "name": "София", "postCode": "1000"}]}');
        $this->ok('{"offices": []}');
        app(PickupPointSyncer::class)->run('econt', 'cli');
        $this->assertTrue($syncer->hasRun('econt'));
        $this->assertCount(2, $this->http->calls);

        // The next ordinary run goes straight to the points...
        $this->ok('{"offices": []}');
        app(PickupPointSyncer::class)->run('econt', 'cli');
        $this->assertCount(3, $this->http->calls);

        // ...and the weekly one (--settlements) rebuilds first.
        $this->ok('{"cities": [{"id": 41, "name": "София", "postCode": "1000"}]}');
        $this->ok('{"offices": []}');
        app(PickupPointSyncer::class)->run('econt', 'cli', withSettlements: true);
        $this->assertCount(5, $this->http->calls);
    }

    public function test_a_failing_settlement_download_never_fails_the_points_sync(): void
    {
        $this->enableCourier('econt');
        $this->http->answer(new CourierResponse(503, 'x'));                          // cities fail
        $this->ok($this->courierFixture('CourierEcont', 'offices.sample.json'));    // offices fine

        $run = app(PickupPointSyncer::class)->run('econt', 'cli');

        $this->assertSame('ok', $run->outcome);
        $this->assertSame(3, $run->unmapped, 'no settlements yet, so every point waits unmapped');
        $this->assertFalse(app(SettlementSyncer::class)->hasRun('econt'), 'so the next run tries again');
    }

    public function test_the_command_with_settlements_rebuilds_them_for_enabled_couriers_only(): void
    {
        $this->enableCourier('econt');
        $this->ok('{"cities": [{"id": 41, "name": "София", "postCode": "1000"}]}');
        $this->ok('{"offices": []}');

        $this->artisan('couriers:sync econt --now --settlements')->assertSuccessful();

        $this->assertSame(['/ee/services/Nomenclatures/NomenclaturesService.getCities.json', '/ee/services/Nomenclatures/NomenclaturesService.getOffices.json'], array_column($this->http->calls, 'path'));

        Queue::fake();
        $this->artisan('couriers:sync --settlements')->assertSuccessful();
        Queue::assertPushed(SyncCourierPointsJob::class, fn (SyncCourierPointsJob $j): bool => $j->carrierCode === 'econt' && $j->withSettlements);
        Queue::assertPushedTimes(SyncCourierPointsJob::class, 1);
        $this->artisan('couriers:sync speedy --settlements')->assertFailed();
    }

    public function test_enabling_a_courier_on_the_page_queues_its_first_sync_with_settlements_once(): void
    {
        Queue::fake();
        $this->actAsPanelAdministrator();

        $update = fn ($component, array $values) => $component->update(
            calls: [['method' => 'save', 'params' => [], 'path' => '']],
            updates: collect($values)->mapWithKeys(fn ($v, $k) => ['data.'.$k => $v])->all(),
        );

        $component = Livewire::test(CouriersSettings::class);
        $update($component, ['econt.enabled' => true, 'econt.test.username' => 'fake-user']);

        Queue::assertPushed(SyncCourierPointsJob::class, fn (SyncCourierPointsJob $j): bool => $j->carrierCode === 'econt' && $j->withSettlements && $j->trigger === 'manual');
        Queue::assertPushedTimes(SyncCourierPointsJob::class, 1);

        $update($component, ['econt.test.username' => 'another-fake-user']);
        Queue::assertPushedTimes(SyncCourierPointsJob::class, 1);

        // Switched off and on again, but its settlements were built meanwhile: no second first-sync.
        $update($component, ['econt.enabled' => false]);
        app(SiteSettingsRepository::class)->set('couriers.econt.settlements_synced_at', '2026-11-26 03:40:00');
        $update($component, ['econt.enabled' => true]);
        Queue::assertPushedTimes(SyncCourierPointsJob::class, 1);
    }

    public function test_the_boxnow_api_host_has_defaults_per_environment_that_stay_editable_within_the_allowlist(): void
    {
        $vault = app(CourierSettings::class);
        $descriptor = new BoxNowDescriptor;

        $this->assertSame('api-stage.boxnow.bg', $vault->plainValues($descriptor, CourierEnvironment::TEST)['api_host']);
        $this->assertSame('api-production.boxnow.bg', $vault->plainValues($descriptor, CourierEnvironment::PRODUCTION)['api_host']);

        // The pre-filled value is on the page, and is an allowed host while the courier is on.
        $this->actAsPanelAdministrator();
        $component = Livewire::test(CouriersSettings::class);
        $this->assertSame('api-stage.boxnow.bg', $component->get('data.boxnow.test.api_host'));
        $this->assertSame('api-production.boxnow.bg', $component->get('data.boxnow.production.api_host'));

        $this->enableBoxNow(withCredentials: false);
        $this->assertContains('api-stage.boxnow.bg', app(CourierDirectory::class)->allowedHosts('boxnow'));

        // Editable, inside the allowlist; a value outside it is dropped, not replaced by the default.
        app(SiteSettingsRepository::class)->set('couriers.boxnow.test.api_host', 'api-custom.boxnow.gr');
        $this->assertSame('api-custom.boxnow.gr', $vault->plainValues($descriptor, CourierEnvironment::TEST)['api_host']);
        app(SiteSettingsRepository::class)->set('couriers.boxnow.test.api_host', 'evil.example.com');
        $this->assertArrayNotHasKey('api_host', $vault->plainValues($descriptor, CourierEnvironment::TEST));
    }

    public function test_the_page_shows_the_short_settlement_report(): void
    {
        $this->enableCourier('speedy');
        $this->ok("id,name,postCode,region\n1,Банкя,1320,СОФИЯ (СТОЛИЦА)\n2,Nowhere,,\n");
        $this->ok('{"offices": []}');
        app(PickupPointSyncer::class)->run('speedy', 'cli');
        $this->actAsPanelAdministrator();

        $html = Livewire::test(CouriersSettings::class)->html();

        $this->assertStringContainsString(e(__('couriers.report.heading')), $html);
        $this->assertStringContainsString(e(__('couriers.report.unmatched_names', ['names' => 'Nowhere'])), $html);
    }

    /** @return list<array{id: int, name: string, postCode: string}> $count Bulgarian cities that map by rule (a) */
    private function cities(int $count): array
    {
        $names = [['София', '1000'], ['Пловдив', '4000'], ['Варна', '9000'], ['Банкя', '1320'], ['Костинброд', '2230'], ['Бов', '2270'], ['Разград', '7200'], ['Лозен', '1151'], ['Берковица', '3500'], ['Видин', '3700']];
        $list = [];

        foreach (array_slice($names, 0, $count) as $i => [$name, $postcode]) {
            // Sofia and Plovdiv carry the ids the Econt office fixture names (41, 25).
            $list[] = ['id' => $name === 'София' ? 41 : ($name === 'Пловдив' ? 25 : 100 + $i), 'name' => $name, 'postCode' => $postcode];
        }

        return $list;
    }

    private function citiesBody(int $count): string
    {
        return (string) json_encode(['cities' => $this->cities($count)]);
    }

    /** @return array{ids: array<int, string>, links: array<string, ?int>} */
    private function state(): array
    {
        return [
            'ids' => DB::table('shipping_settlements')->whereNotNull('econt_city_id')->orderBy('econt_city_id')->pluck('name', 'econt_city_id')->all(),
            'links' => DB::table('shipping_pickup_points')->orderBy('external_ref')->pluck('settlement_id', 'external_ref')->all(),
        ];
    }

    public function test_a_shrunken_settlement_list_is_refused_and_changes_nothing(): void
    {
        $this->enableCourier('econt');
        $this->ok($this->citiesBody(10));
        $this->ok($this->courierFixture('CourierEcont', 'offices.sample.json'));
        app(PickupPointSyncer::class)->run('econt', 'cli');

        $before = $this->state();
        $syncedAt = app(SiteSettingsRepository::class)->get('couriers.econt.settlements_synced_at');
        $this->assertCount(10, $before['ids']);
        $this->assertNotEmpty(array_filter($before['links']));

        // 5 of 10 is 50 %: refused, and the points sync that follows keeps the old links.
        $this->ok($this->citiesBody(5));
        $this->ok($this->courierFixture('CourierEcont', 'offices.sample.json'));
        $run = app(PickupPointSyncer::class)->run('econt', 'cli', withSettlements: true);

        $this->assertSame($before, $this->state());
        $this->assertSame($syncedAt, app(SiteSettingsRepository::class)->get('couriers.econt.settlements_synced_at'), 'a refused build is not remembered');
        $this->assertSame('ok', $run->outcome, 'the points still sync');
        $this->assertSame(0, $run->unmapped);

        try {
            $this->ok($this->citiesBody(5));
            app(SettlementSyncer::class)->run('econt');
            $this->fail('a list under 80 % must be refused');
        } catch (CourierHttpException $e) {
            $this->assertSame('settlements_shrank', $e->closedCode);
        }

        // Exactly 80 % (8 of 10) and 90 % (9 of 10) build normally.
        $this->ok($this->citiesBody(8));
        app(SettlementSyncer::class)->run('econt');
        $this->assertCount(8, $this->state()['ids']);

        $this->ok($this->citiesBody(10));
        app(SettlementSyncer::class)->run('econt');
        $this->assertCount(10, $this->state()['ids']);

        $this->ok($this->citiesBody(9));
        app(SettlementSyncer::class)->run('econt');
        $this->assertCount(9, $this->state()['ids'], '9 of 10 is 90 %: a normal build');
    }

    public function test_an_empty_settlement_list_is_refused_on_the_first_build_and_on_a_later_one(): void
    {
        $this->enableCourier('econt');
        $syncer = app(SettlementSyncer::class);

        // First build: nothing on the table, and still an empty list is not a real answer.
        $this->ok('{"cities": []}');
        try {
            $syncer->run('econt');
            $this->fail('an empty first list must be refused');
        } catch (CourierHttpException $e) {
            $this->assertSame('settlements_shrank', $e->closedCode);
        }

        $this->assertFalse($syncer->hasRun('econt'));
        $this->assertSame(0, DB::table('shipping_settlements')->count(), 'nothing was written, not even the backbone');

        // A list with nothing usable in it (every id broken) counts as empty.
        $this->ok('{"cities": [{"id": "x", "name": "Broken"}]}');
        $this->expectException(CourierHttpException::class);

        try {
            $syncer->run('econt');
        } finally {
            $this->assertFalse($syncer->hasRun('econt'));
        }
    }

    public function test_an_empty_list_after_a_good_build_leaves_the_ids_alone(): void
    {
        $this->enableCourier('econt');
        $this->ok($this->citiesBody(3));
        app(SettlementSyncer::class)->run('econt');
        $before = $this->state();

        $this->ok('{"cities": []}');

        try {
            app(SettlementSyncer::class)->run('econt');
            $this->fail('an empty later list must be refused');
        } catch (CourierHttpException $e) {
            $this->assertSame('settlements_shrank', $e->closedCode);
        }

        $this->assertSame($before, $this->state());
    }

    public function test_a_build_waits_for_the_lock_and_writes_nothing_when_it_cannot_get_it(): void
    {
        $this->enableCourier('econt');
        $this->ok($this->citiesBody(3));

        $other = Cache::lock(SettlementSyncer::LOCK, 60);
        $this->assertTrue($other->get(), 'another build holds the one global lock');

        $syncer = new SettlementSyncer(
            app(CourierDirectory::class), app(CourierSettings::class), app(ShippingProviderResolver::class),
            app(SettlementBuilder::class), app(SiteSettingsRepository::class), lockWaitSeconds: 1,
        );

        try {
            $syncer->run('econt');
            $this->fail('a build that cannot get the lock must throw');
        } catch (LockTimeoutException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, DB::table('shipping_settlements')->count(), 'nothing was written');
        $this->assertSame([], array_slice($this->http->calls, 0, 0));
        $this->assertCount(0, $this->http->calls, 'and the courier was not even asked');
        $this->assertFalse($syncer->hasRun('econt'));

        // The holder lets go: the same build now runs, and releases the lock for the next one.
        $other->release();
        $syncer->run('econt');
        $this->assertCount(3, $this->state()['ids']);
        $again = Cache::lock(SettlementSyncer::LOCK, 5);
        $this->assertTrue($again->get(), 'the build released the lock');
        $again->release();
    }

    public function test_a_courier_id_that_is_not_digits_keeps_the_point_and_leaves_it_unmapped(): void
    {
        $this->enableCourier('econt');
        $this->ok($this->citiesBody(3));
        $offices = json_decode($this->courierFixture('CourierEcont', 'offices.sample.json'), true);
        $offices['offices'][0]['address']['city']['id'] = '41abc';
        $offices['offices'][1]['address']['city']['id'] = str_repeat('9', 19);
        $this->ok((string) json_encode($offices));

        $run = app(PickupPointSyncer::class)->run('econt', 'cli');

        $this->assertSame(3, $run->seen, 'no point is skipped for it');
        $this->assertSame(2, $run->unmapped);
        $this->assertNull(DB::table('shipping_pickup_points')->where('external_ref', '1101')->value('courier_settlement_ref'));
        $this->assertNull(DB::table('shipping_pickup_points')->where('external_ref', '9001')->value('settlement_id'));
    }
}
