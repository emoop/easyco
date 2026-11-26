<?php

namespace Tests\Feature\Couriers;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\StoreTimezone;
use App\Support\BgPostcodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EnablesBoxNow;
use Tests\TestCase;

/**
 * COUR-2a: the two public read endpoints of the checkout picker (GET /api/shipping/settlements and /api/shipping/pickup-points):
 * what they find, what they offer, what they hide, how they are limited and cached. Our own tables only, a fake in every row.
 */
class PickupPointEndpointsTest extends TestCase
{
    use EnablesBoxNow;
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableBoxNow();
        $this->enableCourier('econt');
        app(SiteSettingsRepository::class)->set(StoreTimezone::KEY, 'Europe/Sofia');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --- fixtures -------------------------------------------------------------------------------------------------

    /** @param array<string> $postcodes */
    private function settlement(string $name, string $region = 'PDV', array $postcodes = ['4000'], bool $split = false): int
    {
        return (int) DB::table('shipping_settlements')->insertGetId([
            'country_code' => 'BG', 'name' => $name, 'name_key' => BgPostcodes::normalise($name), 'region_code' => $region,
            'part' => $split ? $postcodes[0] : '', 'postcodes' => json_encode($postcodes), 'source' => 'postcodes', 'is_split' => $split,
        ]);
    }

    /** @param array<string, mixed> $override */
    private function point(?int $settlementId, array $override = []): int
    {
        $this->seq++;

        return (int) DB::table('shipping_pickup_points')->insertGetId($override + [
            'carrier_code' => 'boxnow', 'external_ref' => 'REF'.$this->seq, 'kind' => 'locker', 'name' => 'Point '.$this->seq,
            'settlement_id' => $settlementId, 'settlement_name' => 'Name', 'postcode' => '4000', 'address_line' => 'Street '.$this->seq,
            'latitude' => 42.135400, 'longitude' => 24.745300, 'is_active' => 1,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function search(string $q, string $carrier = 'boxnow', string $kind = 'locker'): array
    {
        return $this->getJson('/api/shipping/settlements?'.http_build_query(['carrier' => $carrier, 'kind' => $kind, 'q' => $q]))->assertOk()->json('data');
    }

    /** @return list<array<string, mixed>> */
    private function points(string $settlement, string $carrier = 'boxnow', string $kind = 'locker'): array
    {
        return $this->getJson('/api/shipping/pickup-points?'.http_build_query(['carrier' => $carrier, 'kind' => $kind, 'settlement' => $settlement]))->assertOk()->json('data');
    }

    // --- the settlement search ------------------------------------------------------------------------------------

    public function test_a_cyrillic_and_a_latin_query_find_the_same_settlement(): void
    {
        $id = $this->settlement('Пловдив');
        $this->point($id);

        foreach (['плов', 'Плов', 'plov', 'PLOVD'] as $q) {
            $found = $this->search($q);

            $this->assertCount(1, $found, $q);
            $this->assertSame((string) $id, $found[0]['id']);
            $this->assertSame('Пловдив', $found[0]['name']);
            $this->assertSame('Пловдив', $found[0]['region'], 'the oblast, from BgRegions');
            $this->assertSame('4000', $found[0]['postcode']);
            $this->assertSame(1, $found[0]['points']);
        }

        $this->assertSame([], $this->search('zzz'));
    }

    public function test_only_settlements_where_this_courier_has_an_offerable_point_of_this_kind_are_returned(): void
    {
        $both = $this->settlement('Бяла');
        $onlyOffice = $this->settlement('Бяла Слатина', 'VRC', ['3200']);
        $otherCourier = $this->settlement('Бяла Черква', 'BGS', ['8000']);
        $inactive = $this->settlement('Бялото');
        $this->point($both, ['kind' => 'locker']);
        $this->point($onlyOffice, ['kind' => 'office']);
        $this->point($otherCourier, ['carrier_code' => 'econt', 'kind' => 'locker']);
        $this->point($inactive, ['is_active' => 0]);

        $this->assertSame([(string) $both], array_column($this->search('бяла', 'boxnow', 'locker'), 'id'));

        $ids = array_column($this->search('бяла', 'boxnow', 'pickup'), 'id');
        sort($ids);
        $expected = [(string) $both, (string) $onlyOffice];
        sort($expected);
        $this->assertSame($expected, $ids, '`pickup` is office AND locker');

        $this->assertSame([(string) $otherCourier], array_column($this->search('бяла', 'econt', 'locker'), 'id'));
    }

    public function test_same_name_places_are_told_apart_by_oblast_and_a_split_part_shows_its_postcode(): void
    {
        $a = $this->settlement('Лозен', 'SOF', ['1151']);
        $b = $this->settlement('Лозен', 'HKV', ['6300']);
        $partOne = $this->settlement('Раковски', 'PDV', ['4150'], true);
        $partTwo = $this->settlement('Раковски', 'PDV', ['4151'], true);

        foreach ([$a, $b, $partOne, $partTwo] as $id) {
            $this->point($id);
        }

        $lozen = $this->search('лозен');
        $this->assertCount(2, $lozen);
        $this->assertEqualsCanonicalizing(['София-град', 'Хасково'], array_map(static fn ($item) => (string) $item['region'], $lozen));
        $this->assertNotSame($lozen[0]['id'], $lozen[1]['id']);

        $rakovski = $this->search('раковски');
        $this->assertCount(2, $rakovski);
        $this->assertSame([true, true], array_column($rakovski, 'is_split'));
        $this->assertEqualsCanonicalizing(['4150', '4151'], array_column($rakovski, 'postcode'));
        $this->assertSame([false, false], array_column($lozen, 'is_split'));
    }

    public function test_points_without_a_settlement_are_found_by_the_name_the_courier_wrote_and_cannot_collide_with_an_id(): void
    {
        $mapped = $this->settlement('Рилци');
        $this->point($mapped);
        $unmapped = $this->point(null, ['settlement_name' => 'Рилски манастир', 'name' => 'Unmapped locker']);
        $this->point(null, ['settlement_name' => 'Рилски Манастир', 'external_ref' => 'SECOND']);

        $found = $this->search('рил');
        $keys = array_column($found, 'id');

        $this->assertContains((string) $mapped, $keys);
        $this->assertContains('n:rilskimanastir', $keys, 'the key is n: + the normalised name');

        $group = $found[array_search('n:rilskimanastir', $keys, true)];
        $this->assertSame('Рилски манастир', $group['name'], 'the name as the courier wrote it');
        $this->assertNull($group['region']);
        $this->assertSame(2, $group['points'], 'both spellings are one group');

        $listed = $this->points('n:rilskimanastir');
        $this->assertCount(2, $listed);
        $this->assertContains($unmapped, array_column($listed, 'id'));
        $this->assertSame([], $this->getJson('/api/shipping/pickup-points?carrier=boxnow&kind=locker&settlement=n:nothing')->assertOk()->json('data'));
    }

    public function test_exact_name_first_then_most_points_then_name(): void
    {
        $long = $this->settlement('Варна');
        $exact = $this->settlement('Вар', 'VAR', ['9000']);
        $many = $this->settlement('Варвара', 'PAZ', ['4400']);
        $this->point($long);
        $this->point($exact);
        $this->point($many);
        $this->point($many);
        $this->point($many);

        $this->assertSame([(string) $exact, (string) $many, (string) $long], array_column($this->search('вар'), 'id'));
    }

    public function test_at_most_twenty_settlements(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->point($this->settlement('Нива '.chr(97 + $i).$i, 'PDV', [(string) (5000 + $i)]));
        }

        $this->assertCount(20, $this->search('нива'));
    }

    public function test_a_bad_query_is_refused_and_a_disabled_or_unknown_courier_is_a_404(): void
    {
        $this->point($this->settlement('Пловдив'));

        foreach (['', 'а', ' а ', '   ', str_repeat('а', 61)] as $q) {
            $this->getJson('/api/shipping/settlements?'.http_build_query(['carrier' => 'boxnow', 'kind' => 'locker', 'q' => $q]))->assertStatus(422);
        }

        $this->getJson('/api/shipping/settlements?'.http_build_query(['carrier' => 'boxnow', 'kind' => 'locker', 'q' => str_repeat('а', 60)]))->assertOk();
        $this->getJson('/api/shipping/settlements?carrier=boxnow&kind=bogus&q=пло')->assertStatus(422);
        $this->getJson('/api/shipping/settlements?kind=locker&q=пло')->assertStatus(422);
        $this->getJson('/api/shipping/settlements?carrier=nobody&kind=locker&q=пло')->assertStatus(404);
        $this->getJson('/api/shipping/pickup-points?carrier=boxnow&kind=locker')->assertStatus(422);

        app(SiteSettingsRepository::class)->set('couriers.econt.enabled', '0');
        $this->point($this->settlement('Бургас', 'BGS', ['8000']), ['carrier_code' => 'econt']);
        $this->getJson('/api/shipping/settlements?carrier=econt&kind=locker&q=бур')->assertStatus(404);
    }

    public function test_the_answers_are_public_cacheable_and_limited_per_ip(): void
    {
        $id = $this->settlement('Пловдив');
        $this->point($id);

        $response = $this->getJson('/api/shipping/settlements?carrier=boxnow&kind=locker&q=пло')->assertOk();
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cache);
        $this->assertStringContainsString('max-age=300', $cache);
        $this->assertStringNotContainsString('no-store', $cache);

        $this->getJson('/api/shipping/pickup-points?carrier=boxnow&kind=locker&settlement='.$id)->assertOk();
        $this->assertStringContainsString('max-age=300', (string) $this->getJson('/api/shipping/pickup-points?carrier=boxnow&kind=locker&settlement='.$id)->headers->get('Cache-Control'));

        // a bad request is never cached
        $this->assertStringNotContainsString('max-age=300', (string) $this->getJson('/api/shipping/settlements?carrier=boxnow&kind=locker&q=а')->headers->get('Cache-Control'));

        config(['ratelimits.pickup-points' => 3]);
        $this->app['cache']->store()->flush();

        $this->getJson('/api/shipping/settlements?carrier=boxnow&kind=locker&q=пло')->assertOk();
        $this->getJson('/api/shipping/settlements?carrier=boxnow&kind=locker&q=пло')->assertOk();
        $this->getJson('/api/shipping/settlements?carrier=boxnow&kind=locker&q=пло')->assertOk();
        $this->getJson('/api/shipping/settlements?carrier=boxnow&kind=locker&q=пло')->assertStatus(429)->assertJsonPath('reason', 'too_many_requests');
    }

    // --- the points ----------------------------------------------------------------------------------------------

    public function test_only_offerable_points_are_listed_and_with_exactly_the_listed_fields(): void
    {
        $id = $this->settlement('Пловдив');
        $ok = $this->point($id, ['name' => 'B point', 'external_ref' => 'OK-B', 'features' => json_encode(['state' => 'boxnow-ready', 'secret_internal' => 'x'])]);
        $first = $this->point($id, ['name' => 'A point', 'external_ref' => 'OK-A', 'latitude' => null, 'longitude' => null, 'postcode' => null]);
        $this->point($id, ['is_active' => 0, 'external_ref' => 'INACTIVE']);
        $this->point($id, ['kind' => 'office', 'external_ref' => 'OFFICE']);
        $this->point($id, ['courier_type' => 'mps', 'external_ref' => 'MPS']);
        $this->point($id, ['carrier_code' => 'econt', 'external_ref' => 'OTHERCOURIER']);

        $list = $this->points((string) $id);

        $this->assertSame(['OK-A', 'OK-B'], array_column($list, 'ref'), 'sorted by name');
        $this->assertSame([$first, $ok], array_column($list, 'id'), 'OUR id');
        $this->assertSame(['address', 'id', 'kind', 'lat', 'lng', 'name', 'postcode', 'ref'], collect($list[0])->keys()->sort()->values()->all(), 'exactly these fields');
        $this->assertNull($list[0]['lat']);
        $this->assertNull($list[0]['postcode']);
        $this->assertSame(24.7453, $list[1]['lng']);
        $this->assertStringNotContainsString('secret_internal', json_encode($list));

        $both = $this->points((string) $id, 'boxnow', 'pickup');
        $this->assertEqualsCanonicalizing(['OK-A', 'OK-B', 'OFFICE'], array_column($both, 'ref'), '`pickup` returns both kinds');
    }

    public function test_a_speedy_office_that_does_not_allow_pickup_or_has_expired_is_not_offered(): void
    {
        $this->enableCourier('speedy');
        $id = $this->settlement('Пловдив');
        // 22:30 UTC on the 26th is 00:30 on the 27th in Sofia: the store's "today" is the 27th
        Carbon::setTestNow(Carbon::parse('2026-11-26 22:30:00', 'UTC'));

        $mk = fn (string $ref, ?array $features) => $this->point($id, ['carrier_code' => 'speedy', 'kind' => 'office', 'external_ref' => $ref, 'features' => $features === null ? null : json_encode($features)]);
        $mk('NO-FEATURES', null);
        $mk('ALLOWED', ['pickUpAllowed' => true]);
        $mk('ABSENT', ['cardPaymentAllowed' => true]);
        $mk('NOT-ALLOWED', ['pickUpAllowed' => false]);
        $mk('EXPIRED-YESTERDAY-IN-STORE', ['validTo' => '2026-11-26']);
        $mk('VALID-TODAY-IN-STORE', ['validTo' => '2026-11-27']);
        $mk('VALID-LATER', ['validTo' => '2030-12-31T00:00:00']);
        $mk('EXPIRED-LONG-AGO', ['validTo' => '2020-01-01']);

        $refs = array_column($this->points((string) $id, 'speedy', 'office'), 'ref');
        sort($refs);

        $this->assertSame(['ABSENT', 'ALLOWED', 'NO-FEATURES', 'VALID-LATER', 'VALID-TODAY-IN-STORE'], $refs);

        // and the settlement count follows the same definition
        $this->assertSame(5, $this->search('пло', 'speedy', 'office')[0]['points']);
    }
}
