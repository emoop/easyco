<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\PlacesRegionOrders;
use Tests\Concerns\RestoresSchemaWithoutRemigrating;
use Tests\TestCase;

/**
 * Order context, stage C2-A (order-context-design.md 2.1, 2.2): the two tables as the database really holds them — the foreign keys restrict
 * a real delete, both unique keys refuse a real duplicate, the column types are what the design says (a real SHOW CREATE TABLE), every
 * identifier is within MySQL's 64 characters (CLAUDE.md rule 5, asserted not trusted), the CHECKs refuse a bad value, and the migrations are
 * idempotent (rule 6): running one again changes nothing, and a down() / up() round trip restores the schema.
 */
class OrderContextStorageTest extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;
    use RestoresSchemaWithoutRemigrating;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->setUpRegionOrders();
        $this->captureDatabaseFingerprint();
    }

    protected function tearDown(): void
    {
        $this->databaseMatchesFingerprint();
        parent::tearDown();
        $this->skipRemigrationIfClean();
    }

    private function orderWithContext(bool $invoice = true, string $type = 'company'): string
    {
        $extra = $invoice ? ['invoice' => ['recipient_type' => $type, 'legal_name' => 'Acme', 'country' => 'BG', 'city' => 'Sofia', 'postal_code' => '1000', 'address_line_1' => '1 Main St'] + ($type === 'company' ? ['tax_id' => '1'] : [])] : [];

        return (string) $this->checkout($this->streetFields('BG'), $extra)->assertStatus(201)->json('order.id');
    }

    /** SHOW CREATE TABLE, without the collation noise of every text column and without the AUTO_INCREMENT counter (neither is the schema). */
    private function create(string $table): string
    {
        return (string) preg_replace(['/ COLLATE utf8mb4_unicode_ci(?= (NOT NULL|DEFAULT|,|
))/', '/ AUTO_INCREMENT=\d+/'], '', (string) DB::selectOne('SHOW CREATE TABLE `'.$table.'`')->{'Create Table'});
    }

    /** @return array<string, array{0: string}> */
    public static function tables(): array
    {
        return ['order_attributions' => ['order_attributions'], 'order_invoice_details' => ['order_invoice_details']];
    }

    // ---- foreign keys and unique keys, proven by real statements ------------------------------------------------------------------------

    /** Takes away every row that references the order EXCEPT those of the two context tables, so only they are left to refuse its deletion. */
    private function clearOtherReferences(string $order): void
    {
        $references = DB::select(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?',
            [DB::getDatabaseName(), 'orders', 'id'],
        );

        foreach ($references as $reference) {
            if (! in_array($reference->t, ['order_attributions', 'order_invoice_details'], true)) {
                DB::table($reference->t)->where($reference->c, $order)->delete();
            }
        }
    }

    private function assertDeleteIsRefusedBy(string $order, string $constraint): void
    {
        try {
            DB::table('orders')->where('id', $order)->delete();
            $this->fail("deleting the order was allowed: {$constraint} did not hold");
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->errorInfo[0]);
            $this->assertSame(1451, $e->errorInfo[1], 'a restricted delete');
            $this->assertStringContainsString($constraint, $e->getMessage());
        }
    }

    public function test_the_attribution_foreign_key_restricts_a_real_delete_of_the_order(): void
    {
        $order = $this->orderWithContext(false);
        $this->clearOtherReferences($order);

        $this->assertDeleteIsRefusedBy($order, 'attr_order_id_foreign');
        $this->assertSame(1, DB::table('orders')->where('id', $order)->count());
    }

    public function test_the_invoice_foreign_key_restricts_a_real_delete_of_the_order(): void
    {
        $order = $this->orderWithContext();
        $this->clearOtherReferences($order);
        DB::table('order_attributions')->where('order_id', $order)->delete();

        $this->assertDeleteIsRefusedBy($order, 'oid_order_id_foreign');
        $this->assertSame(1, DB::table('orders')->where('id', $order)->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tables')]
    public function test_one_row_per_order_is_a_real_unique_key(string $table): void
    {
        $order = $this->orderWithContext();
        $row = (array) DB::table($table)->where('order_id', $order)->first();
        unset($row['id']);

        try {
            DB::table($table)->insert($row);
            $this->fail('a second row for the same order was accepted');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->errorInfo[0]);
            $this->assertSame(1062, $e->errorInfo[1], 'a duplicate key (SQLSTATE 23000 + 1062, CLAUDE.md rule 3)');
            $this->assertStringContainsString($table === 'order_attributions' ? 'attr_order_id_unique' : 'oid_order_id_unique', $e->getMessage());
        }
    }

    public function test_an_order_that_does_not_exist_cannot_have_a_context_row(): void
    {
        $row = (array) DB::table('order_attributions')->where('order_id', $this->orderWithContext(false))->first();
        unset($row['id']);
        $row['order_id'] = 999999;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('attr_order_id_foreign');
        DB::table('order_attributions')->insert($row);
    }

    // ---- the columns --------------------------------------------------------------------------------------------------------------------

    public function test_the_attribution_columns_are_what_the_design_says(): void
    {
        $create = $this->create('order_attributions');

        foreach ([
            '`order_id` bigint unsigned NOT NULL', '`source_type` varchar(255) NOT NULL', '`capture_status` varchar(255) NOT NULL',
            '`referrer_url` varchar(500) DEFAULT NULL', '`click_id_type` varchar(32) DEFAULT NULL', '`ai_platform` varchar(64) DEFAULT NULL',
            '`landing_page_url` varchar(500) DEFAULT NULL', '`session_pages` smallint unsigned DEFAULT NULL', '`session_count` smallint unsigned DEFAULT NULL',
            '`device_type` varchar(16) DEFAULT NULL', '`user_agent` varchar(255) DEFAULT NULL', '`first_landing_page_url` varchar(500) DEFAULT NULL',
            '`visitor_id` char(36) DEFAULT NULL', '`account_id` bigint unsigned DEFAULT NULL', '`consent_mode` varchar(16) NOT NULL',
            '`analytics_consent` varchar(16) NOT NULL', '`consent_source` varchar(24) NOT NULL', '`ip` varchar(45) DEFAULT NULL',
            '`ip_anonymised_at` timestamp NULL DEFAULT NULL', '`captured_at` timestamp NOT NULL',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $create);
        }

        // No foreign key on account_id: a snapshot that outlives the account.
        $this->assertStringNotContainsString('FOREIGN KEY (`account_id`)', $create);
        foreach (['attr_order_id_foreign', 'attr_order_id_unique', 'attr_source_type_index', 'attr_visitor_id_index', 'attr_captured_at_index'] as $name) {
            $this->assertStringContainsString('`'.$name.'`', $create);
        }
        $this->assertStringContainsString('ON DELETE RESTRICT', $create);
    }

    public function test_the_invoice_columns_are_what_the_design_says(): void
    {
        $create = $this->create('order_invoice_details');

        foreach ([
            '`order_id` bigint unsigned NOT NULL', '`requested_at` timestamp NOT NULL', '`recipient_type` varchar(255) NOT NULL', '`legal_name` varchar(255) NOT NULL',
            '`tax_id` varchar(255) DEFAULT NULL', '`company_registration_number` varchar(255) DEFAULT NULL', '`representative` varchar(255) DEFAULT NULL',
            '`country` varchar(255) NOT NULL', '`city` varchar(255) NOT NULL', '`postal_code` varchar(255) NOT NULL', '`address_line_1` varchar(255) NOT NULL',
            '`address_line_2` varchar(255) DEFAULT NULL', '`invoice_email` varchar(255) NOT NULL', '`country_rule_version` varchar(255) DEFAULT NULL',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $create);
        }

        foreach (['oid_order_id_foreign', 'oid_order_id_unique'] as $name) {
            $this->assertStringContainsString('`'.$name.'`', $create);
        }
        foreach (['requested', 'invoice_number', 'status', 'tax_amount'] as $absent) {
            $this->assertStringNotContainsString('`'.$absent.'`', $create, "no {$absent} column: the row is a request snapshot, never a document");
        }
    }

    public function test_every_identifier_is_within_the_sixty_four_character_limit(): void
    {
        $names = [];

        foreach (['order_attributions', 'order_invoice_details'] as $table) {
            $names[] = $table;
            $names = array_merge($names, Schema::getColumnListing($table));
            $names = array_merge($names, array_map(fn ($i) => $i['name'], Schema::getIndexes($table)));
            $names = array_merge($names, array_map(fn ($f) => (string) $f['name'], Schema::getForeignKeys($table)));
            $names = array_merge($names, array_map(fn ($c) => $c->CONSTRAINT_NAME, DB::select('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [DB::getDatabaseName(), $table])));
        }

        $this->assertGreaterThan(40, count($names));

        foreach (array_unique($names) as $name) {
            $this->assertLessThanOrEqual(64, strlen($name), "{$name} is longer than 64 characters");
        }
    }

    // ---- the CHECKs (rule 2) -----------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> table, column, bad value, check name */
    public static function badValues(): array
    {
        return [
            'source_type' => ['order_attributions', 'source_type', 'telepathy', 'attr_source_type_check'],
            'capture_status' => ['order_attributions', 'capture_status', 'maybe', 'attr_capture_status_check'],
            'consent_mode' => ['order_attributions', 'consent_mode', 'anything', 'attr_consent_mode_check'],
            'analytics_consent' => ['order_attributions', 'analytics_consent', 'perhaps', 'attr_analytics_consent_check'],
            'consent_source' => ['order_attributions', 'consent_source', 'dream', 'attr_consent_source_check'],
            'device_type' => ['order_attributions', 'device_type', 'fridge', 'attr_device_type_check'],
            'recipient_type' => ['order_invoice_details', 'recipient_type', 'government', 'oid_recipient_type_check'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badValues')]
    public function test_a_value_outside_the_enum_is_refused_by_the_database_itself(string $table, string $column, string $value, string $check): void
    {
        $order = $this->orderWithContext(true, 'individual');

        try {
            DB::table($table)->where('order_id', $order)->update([$column => $value]);
            $this->fail("{$column} accepted {$value}");
        } catch (QueryException $e) {
            $this->assertSame(3819, $e->errorInfo[1], 'a violated CHECK constraint');
            $this->assertStringContainsString($check, $e->getMessage());
        }
    }

    public function test_an_individual_cannot_carry_a_tax_id_even_with_a_raw_write(): void
    {
        $order = $this->orderWithContext();

        try {
            DB::table('order_invoice_details')->where('order_id', $order)->update(['recipient_type' => 'individual']);
            $this->fail('a company row with a tax id was turned into an individual keeping it');
        } catch (QueryException $e) {
            $this->assertStringContainsString('oid_individual_no_tax_id_check', $e->getMessage());
        }
    }

    // ---- idempotent migrations (rule 6) ------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> */
    public static function migrations(): array
    {
        return [
            'attributions' => ['2026_10_18_000001_create_order_attributions_table.php', 'order_attributions'],
            'invoice details' => ['2026_10_18_000002_create_order_invoice_details_table.php', 'order_invoice_details'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('migrations')]
    public function test_running_a_migration_again_changes_nothing_and_a_round_trip_restores_the_table(string $file, string $table): void
    {
        $this->orderWithContext();
        $migration = require database_path('migrations/'.$file);
        $before = $this->create($table);
        $rows = DB::table($table)->count();

        $migration->up();
        $migration->up();

        $this->assertSame($before, $this->create($table), 'up() on an existing table is a no-op');
        $this->assertSame($rows, DB::table($table)->count());

        // down() then up() builds it again with every key and CHECK, from nothing (the rows are gone with the table, as they must be).
        DB::table('order_invoice_details')->delete();
        DB::table('order_attributions')->delete();
        $migration->down();
        $this->assertFalse(Schema::hasTable($table));

        $migration->up();
        $this->assertSame($before, $this->create($table), 'the rebuilt table is the same table');
    }
}
