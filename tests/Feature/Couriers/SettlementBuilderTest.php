<?php

namespace Tests\Feature\Couriers;

use App\Couriers\Settlements\SettlementBuilder;
use App\Couriers\Settlements\SettlementReport;
use App\Support\BgPostcodes;
use EasyCo\Shipping\Carrier\CourierSettlement;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * courier-integration-design.md §3.3: the match matrix of the settlement mapping, on the REAL postcode data. The names used:
 * Банкя (София-град 1320, Перник 2466), Лозен (five regions), Клисура (four, two of them the two Sofias), Бов (one row, postcodes
 * 2270 and 2271), Пловдив (PDV 4000). Cyrillic and Latin spellings meet in BgPostcodes::normalise().
 */
class SettlementBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function builder(): SettlementBuilder
    {
        return app(SettlementBuilder::class);
    }

    private function speedy(string $id, string $name, ?string $postcode = null, ?string $region = null): CourierSettlement
    {
        return new CourierSettlement('speedy', $id, $name, $postcode, $region);
    }

    private function econt(string $id, string $name, ?string $postcode = null, ?string $region = null): CourierSettlement
    {
        return new CourierSettlement('econt', $id, $name, $postcode, $region);
    }

    /** @return list<object> the rows of a normalised name */
    private function rows(string $name): array
    {
        return DB::table('shipping_settlements')->where('name_key', BgPostcodes::normalise($name))->orderBy('region_code')->orderBy('part')->get()->all();
    }

    private function rowOf(string $name, string $region, string $part = ''): ?object
    {
        return DB::table('shipping_settlements')->where('name_key', BgPostcodes::normalise($name))->where('region_code', $region)->where('part', $part)->first();
    }

    private function snapshot(): array
    {
        return DB::table('shipping_settlements')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function test_the_backbone_is_the_postcode_data_grouped_by_region_and_name(): void
    {
        $result = $this->builder()->build([]);

        $groups = [];
        foreach (BgPostcodes::entries() as $postcode => $pairs) {
            foreach ($pairs as [$name, $region]) {
                $groups[$region."\0".BgPostcodes::normalise($name)][$postcode] = true;
            }
        }

        $this->assertSame(count($groups), DB::table('shipping_settlements')->count());
        $this->assertSame(count($groups), $result->backbone);
        $this->assertSame(count($groups), $result->created);
        $this->assertSame(0, DB::table('shipping_settlements')->where('source', 'courier')->count());

        // A name with several postcodes is ONE row holding all of them.
        $bov = $this->rowOf('Бов', 'SFO');
        $this->assertSame(['2270', '2271'], json_decode($bov->postcodes, true));
        $this->assertCount(1, DB::table('shipping_settlements')->where('region_code', 'SFO')->where('name_key', 'bov')->get());

        // Sofia city is one row (the file gives it its one postcode), never one per district postcode.
        $sofia = DB::table('shipping_settlements')->where('region_code', 'SOF')->where('name_key', 'sofia')->get();
        $this->assertCount(1, $sofia);
        $this->assertContains('1000', json_decode($sofia[0]->postcodes, true));
    }

    public function test_rule_a_postcode_and_name_match_whatever_the_region_and_the_script(): void
    {
        foreach (['Банкя', 'Bankya', 'БАНКЯ', 'гр. Банкя'] as $i => $spelling) {
            DB::table('shipping_settlements')->delete();
            $result = $this->builder()->build(['speedy' => [$this->speedy('10', $spelling, '1320')]]);

            $this->assertSame(10, (int) $this->rowOf('Банкя', 'SOF')->speedy_site_id, $spelling);
            $this->assertNull($this->rowOf('Банкя', 'PER')->speedy_site_id, 'the same name in another region is untouched: '.$spelling);
            $this->assertSame(1, $result->byPostcode['speedy']);
        }

        $this->assertSame('1320', $this->rowOf('Банкя', 'SOF')->postcodes ? json_decode($this->rowOf('Банкя', 'SOF')->postcodes, true)[0] : null);
    }

    public function test_rule_b_name_and_one_region_match_when_the_postcode_does_not(): void
    {
        // No postcode, and a postcode that is not Lozen's: the region alone settles it.
        $this->builder()->build(['speedy' => [$this->speedy('20', 'Lozen', null, 'Pazardzhik'), $this->speedy('21', 'Лозен', '9999', 'Хасково')]]);

        $this->assertSame(20, (int) $this->rowOf('Лозен', 'PAZ')->speedy_site_id);
        $this->assertSame(21, (int) $this->rowOf('Лозен', 'HKV')->speedy_site_id);
        $this->assertNull($this->rowOf('Лозен', 'SOF')->speedy_site_id);
        $this->assertSame(0, DB::table('shipping_settlements')->where('source', 'courier')->count());
    }

    public function test_the_capitals_two_oblasts_are_told_apart_by_how_the_courier_names_the_region(): void
    {
        $this->builder()->build(['econt' => [
            $this->econt('30', 'Банкя', null, 'София (столица)'),
            $this->econt('31', 'Клисура', null, 'София'),         // the city or the province: ambiguous
            $this->econt('32', 'Lozen', null, 'SOFIA (STOLICHNA)'),
        ]]);

        $this->assertSame(30, (int) $this->rowOf('Банкя', 'SOF')->econt_city_id, 'the capital');
        $this->assertNull($this->rowOf('Клисура', 'SOF')->econt_city_id);
        $this->assertNull($this->rowOf('Клисура', 'SFO')->econt_city_id, 'two Klisuras in the two Sofias: ambiguous, so no match');
        $this->assertSame(32, (int) $this->rowOf('Lozen', 'SOF')->econt_city_id, 'a Latin region name is read too');
        $this->assertSame(1, DB::table('shipping_settlements')->where('source', 'courier')->count(), 'only the ambiguous Klisura has a row of its own');
    }

    public function test_an_ambiguous_name_and_region_is_no_match_and_an_unmatched_settlement_gets_its_own_flagged_row(): void
    {
        $result = $this->builder()->build(['speedy' => [
            $this->speedy('40', 'Клисура', null, 'София'),        // two regions possible -> ambiguous
            $this->speedy('41', 'Лозен'),                         // no postcode, no region
            $this->speedy('42', 'Несъществуващо село', '1111', 'Пловдив'),
        ]]);

        $this->assertSame(3, $result->unmatched['speedy']);
        $this->assertSame(0, $result->byPostcode['speedy'] ?? 0);
        $this->assertSame(0, $result->byRegion['speedy'] ?? 0);

        // The backbone rows named like them stay without an id: never matched by name alone across regions.
        foreach ($this->rows('Лозен') as $row) {
            if ($row->source === 'postcodes') {
                $this->assertNull($row->speedy_site_id);
            }
        }

        $own = DB::table('shipping_settlements')->where('source', 'courier')->orderBy('speedy_site_id')->get();
        $this->assertSame([40, 41, 42], $own->pluck('speedy_site_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['s40', 's41', 's42'], $own->pluck('part')->all());
        $this->assertSame('PDV', $own[2]->region_code, 'a readable region is kept');
        $this->assertSame('', $own[0]->region_code);
        $this->assertSame(['1111'], json_decode($own[2]->postcodes, true));
        $this->assertSame(3, app(SettlementReport::class)->for('speedy')['unmatched']);
    }

    public function test_one_row_matched_by_two_ids_of_a_courier_is_split_by_postcode(): void
    {
        $result = $this->builder()->build(['speedy' => [$this->speedy('50', 'Бов', '2270'), $this->speedy('51', 'Бов', '2271')]]);

        $this->assertSame(1, $result->splits['speedy']);
        $base = $this->rowOf('Бов', 'SFO');
        $this->assertTrue((bool) $base->is_split);
        $this->assertNull($base->speedy_site_id);
        $this->assertSame([], json_decode($base->postcodes, true), 'both postcodes were claimed by the parts');

        $a = $this->rowOf('Бов', 'SFO', '2270');
        $b = $this->rowOf('Бов', 'SFO', '2271');
        $this->assertSame([50, 51], [(int) $a->speedy_site_id, (int) $b->speedy_site_id]);
        $this->assertSame([['2270'], ['2271']], [json_decode($a->postcodes, true), json_decode($b->postcodes, true)]);
        $this->assertTrue((bool) $a->is_split && (bool) $b->is_split);
        $this->assertSame('postcodes', $a->source);

        // Re-running with the same input finds each part by its own postcode: no new split, nothing changes.
        $before = $this->snapshot();
        $again = $this->builder()->build(['speedy' => [$this->speedy('50', 'Бов', '2270'), $this->speedy('51', 'Бов', '2271')]]);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([0, 0], [$again->created, $again->updated]);
        $this->assertSame([], $again->splits);
    }

    public function test_a_split_whose_postcodes_cannot_tell_the_parts_apart_uses_the_id(): void
    {
        $this->builder()->build(['econt' => [$this->econt('60', 'Бов', '2270'), $this->econt('61', 'Бов', '2270')]]);

        $this->assertSame(60, (int) $this->rowOf('Бов', 'SFO', 'x60')->econt_city_id);
        $this->assertSame(61, (int) $this->rowOf('Бов', 'SFO', 'x61')->econt_city_id);
        $this->assertSame(['2270', '2271'], json_decode($this->rowOf('Бов', 'SFO')->postcodes, true), 'no postcode was claimed');

        $before = $this->snapshot();
        $this->builder()->build(['econt' => [$this->econt('60', 'Бов', '2270'), $this->econt('61', 'Бов', '2270')]]);
        $this->assertSame($before, $this->snapshot(), 'idempotent');
    }

    public function test_a_rerun_with_the_same_input_changes_nothing_and_writes_nothing(): void
    {
        $lists = [
            'speedy' => [$this->speedy('70', 'Банкя', '1320'), $this->speedy('71', 'Lozen', null, 'Pazardzhik'), $this->speedy('72', 'Nowhere')],
            'econt' => [$this->econt('80', 'Пловдив', '4000', 'Пловдив'), $this->econt('81', 'Софи', '1000')],
        ];

        $first = $this->builder()->build($lists);
        $this->assertGreaterThan(0, $first->created);
        $before = $this->snapshot();

        $second = $this->builder()->build($lists);

        $this->assertSame($before, $this->snapshot(), 'the same rows, the same ids, the same order');
        $this->assertSame([0, 0], [$second->created, $second->updated]);
    }

    public function test_a_courier_id_that_moves_to_another_row_does_not_trip_the_unique_key_and_nothing_is_deleted(): void
    {
        $this->builder()->build(['speedy' => [$this->speedy('90', 'Банкя', '1320'), $this->speedy('91', 'Nowhere')]]);
        $rows = DB::table('shipping_settlements')->count();

        // 90 now means Banya's namesake in Pernik; 91 is gone from the list.
        $this->builder()->build(['speedy' => [$this->speedy('90', 'Банкя', '2466')]]);

        $this->assertNull($this->rowOf('Банкя', 'SOF')->speedy_site_id);
        $this->assertSame(90, (int) $this->rowOf('Банкя', 'PER')->speedy_site_id);
        $gone = DB::table('shipping_settlements')->where('part', 's91')->first();
        $this->assertNotNull($gone, 'a settlement is never deleted');
        $this->assertNull($gone->speedy_site_id, 'only its id is cleared');
        $this->assertSame($rows, DB::table('shipping_settlements')->count());

        // The database itself refuses one courier id on two rows.
        try {
            DB::table('shipping_settlements')->where('name_key', 'lozen')->where('region_code', 'PAZ')->update(['speedy_site_id' => 90]);
            $this->fail('one Speedy site on two rows must be refused by the unique key');
        } catch (QueryException $e) {
            $this->assertSame(1062, $e->errorInfo[1]);
        }
    }

    public function test_a_partial_change_leaves_the_ids_of_unchanged_rows_in_place(): void
    {
        $this->builder()->build(['speedy' => [$this->speedy('1', 'Банкя', '1320'), $this->speedy('2', 'Пловдив', '4000')]]);

        // Only the second id moves; the first row is not rewritten and must keep its id.
        $this->builder()->build(['speedy' => [$this->speedy('1', 'Банкя', '1320'), $this->speedy('3', 'Пловдив', '4000')]]);

        $this->assertSame(1, (int) $this->rowOf('Банкя', 'SOF')->speedy_site_id);
        $this->assertSame(3, (int) $this->rowOf('Пловдив', 'PDV')->speedy_site_id);
        $this->assertSame(2, DB::table('shipping_settlements')->whereNotNull('speedy_site_id')->count());
    }

    public function test_each_courier_only_touches_its_own_column_and_both_can_share_a_row(): void
    {
        $this->builder()->build(['speedy' => [$this->speedy('100', 'Пловдив', '4000')]]);
        $this->builder()->build(['econt' => [$this->econt('200', 'Plovdiv', '4000', 'Plovdiv')]]);

        $row = $this->rowOf('Пловдив', 'PDV');
        $this->assertSame([100, 200], [(int) $row->speedy_site_id, (int) $row->econt_city_id]);

        // Rebuilding Econt alone leaves Speedy's id where it was.
        $this->builder()->build(['econt' => [$this->econt('200', 'Plovdiv', '4000', 'Plovdiv')]]);
        $this->assertSame(100, (int) $this->rowOf('Пловдив', 'PDV')->speedy_site_id);
    }

    public function test_unusable_courier_ids_are_skipped_and_counted(): void
    {
        $result = $this->builder()->build(['speedy' => [$this->speedy('abc', 'Банкя', '1320'), $this->speedy('7', 'Банкя', '1320'), $this->speedy('7', 'Банкя', '1320')]]);

        $this->assertSame(2, $result->skipped['speedy'], 'a non-numeric id and a repeated one');
        $this->assertSame(7, (int) $this->rowOf('Банкя', 'SOF')->speedy_site_id);
    }

    public function test_the_report_counts_matched_unmatched_and_split_settlements(): void
    {
        $this->builder()->build(['speedy' => [
            $this->speedy('1', 'Банкя', '1320'),
            $this->speedy('2', 'Nowhere'),
            $this->speedy('3', 'Бов', '2270'),
            $this->speedy('4', 'Бов', '2271'),
        ]]);

        $report = app(SettlementReport::class)->for('speedy');

        $this->assertSame(3, $report['mapped'], 'Банкя and the two Бов parts');
        $this->assertSame(1, $report['unmatched']);
        $this->assertSame(['Nowhere'], $report['unmatched_names']);
        $this->assertSame(2, $report['splits']);
        $this->assertSame(['Бов'], $report['split_names']);
        $this->assertSame(0, app(SettlementReport::class)->for('boxnow')['splits'], 'BOX NOW has no settlement ids of its own');
    }
}
