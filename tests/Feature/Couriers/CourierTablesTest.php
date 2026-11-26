<?php

namespace Tests\Feature\Couriers;

use EasyCo\Shipping\Carrier\PickupPointKind;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** courier-integration-design.md §3.1: the tables as built, read back with SHOW CREATE TABLE (rule 2: the constraints are real). */
class CourierTablesTest extends TestCase
{
    use RefreshDatabase;

    private function create(string $table): string
    {
        return (string) DB::selectOne('SHOW CREATE TABLE '.$table)->{'Create Table'};
    }

    private function point(array $override = []): array
    {
        return $override + ['carrier_code' => 'boxnow', 'external_ref' => 'P1', 'kind' => 'locker', 'name' => 'n', 'settlement_name' => 's', 'address_line' => 'a'];
    }

    public function test_the_pickup_points_table_has_the_columns_keys_and_check_of_the_design(): void
    {
        $sql = $this->create('shipping_pickup_points');

        foreach ([
            '`id` bigint unsigned NOT NULL AUTO_INCREMENT',
            '`carrier_code` varchar(32)',
            '`external_ref` varchar(64)',
            '`external_id` varchar(64)',
            '`kind` varchar(10)',
            '`courier_type` varchar(16)',
            '`name` varchar(255)',
            '`settlement_id` bigint unsigned DEFAULT NULL',
            '`settlement_name` varchar(120)',
            '`postcode` varchar(12)',
            '`address_line` varchar(255)',
            '`latitude` decimal(9,6)',
            '`longitude` decimal(9,6)',
            '`features` json',
            '`opening_hours` json',
            '`is_active` tinyint(1) NOT NULL DEFAULT \'1\'',
            '`first_seen_at` timestamp(6)',
            '`synced_at` timestamp(6)',
            '`deactivated_at` timestamp(6)',
            'UNIQUE KEY `spp_carrier_ref_uq` (`carrier_code`,`external_ref`)',
            'KEY `spp_lookup_idx` (`carrier_code`,`kind`,`is_active`,`settlement_id`)',
            'CONSTRAINT `spp_kind_check` CHECK',
        ] as $expected) {
            $this->assertStringContainsString($expected, $sql);
        }

        $this->assertStringNotContainsString('FOREIGN KEY', $sql, 'settlement_id gets its FK in COUR-1b, with shipping_settlements');
    }

    public function test_the_database_itself_refuses_a_second_row_for_one_courier_point_and_a_bad_kind(): void
    {
        DB::table('shipping_pickup_points')->insert($this->point());

        // Rule 3: SQLSTATE 23000 + MySQL 1062, never the message.
        try {
            DB::table('shipping_pickup_points')->insert($this->point(['name' => 'again']));
            $this->fail('a duplicate (carrier, reference) must be refused by the unique key');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->errorInfo[0]);
            $this->assertSame(1062, $e->errorInfo[1]);
        }

        DB::table('shipping_pickup_points')->insert($this->point(['carrier_code' => 'econt']));
        $this->assertSame(2, DB::table('shipping_pickup_points')->count(), 'the same reference under another courier is fine');

        foreach (['', 'depot', 'LOCKER'] as $kind) {
            try {
                DB::table('shipping_pickup_points')->insert($this->point(['external_ref' => 'K'.$kind, 'kind' => $kind]));
                $this->fail("kind \"{$kind}\" must be refused by the CHECK");
            } catch (QueryException $e) {
                $this->assertSame('HY000', $e->errorInfo[0]);
                $this->assertSame(3819, $e->errorInfo[1], 'ER_CHECK_CONSTRAINT_VIOLATED');
            }
        }

        foreach (PickupPointKind::cases() as $kind) {
            DB::table('shipping_pickup_points')->insert($this->point(['external_ref' => 'ok-'.$kind->value, 'kind' => $kind->value]));
        }

        $this->addToAssertionCount(1);
    }

    public function test_the_sync_runs_table_has_the_columns_index_and_outcome_check(): void
    {
        $sql = $this->create('courier_sync_runs');

        foreach ([
            '`carrier_code` varchar(32)',
            '`trigger` varchar(10)',
            '`started_at` timestamp(6) NOT NULL',
            '`finished_at` timestamp(6)',
            '`outcome` varchar(16)',
            '`seen` int unsigned NOT NULL DEFAULT \'0\'',
            '`new_count` int unsigned',
            '`changed` int unsigned',
            '`deactivated` int unsigned',
            '`unmapped` int unsigned',
            '`skipped` int unsigned',
            '`error_code` varchar(64)',
            'KEY `csr_carrier_started_idx` (`carrier_code`,`started_at`)',
            'CONSTRAINT `csr_outcome_check` CHECK',
        ] as $expected) {
            $this->assertStringContainsString($expected, $sql);
        }

        $this->assertStringNotContainsString('password', strtolower($sql));
        $this->assertStringNotContainsString('secret', strtolower($sql));

        try {
            DB::table('courier_sync_runs')->insert(['carrier_code' => 'boxnow', 'trigger' => 'cli', 'started_at' => now(), 'outcome' => 'weird']);
            $this->fail('an unknown outcome must be refused by the CHECK');
        } catch (QueryException $e) {
            $this->assertSame(3819, $e->errorInfo[1]);
        }
    }

    public function test_every_identifier_is_within_the_mysql_limit(): void
    {
        foreach (['spp_carrier_ref_uq', 'spp_lookup_idx', 'spp_kind_check', 'csr_carrier_started_idx', 'csr_outcome_check'] as $name) {
            $this->assertLessThanOrEqual(64, strlen($name), $name);
        }
    }
}
