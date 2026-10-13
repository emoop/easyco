<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\PlacesRegionOrders;
use Tests\Concerns\RestoresSchemaWithoutRemigrating;
use Tests\TestCase;

/**
 * Checkout form v2, stage C1d-B (order-context-design.md 2.3, checkout-form-v2-design.md 6): the six nullable columns on `orders` and
 * `order_placement_snapshots` and the two CHECKs per table. The migration is run directly (it has already run on the test database); DDL
 * commits implicitly in MySQL, so tearDown puts the schema back and the fingerprint trait proves the database is unchanged.
 */
class CheckoutChoicesMigrationTest extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;
    use RestoresSchemaWithoutRemigrating;

    private const PATH = 'packages/EasyCo/Order/database/migrations/2026_10_16_000001_add_checkout_choices_to_orders_and_snapshots.php';

    private const COLUMNS = ['terms_accepted_at', 'terms_version', 'confirmation_requested', 'call_before_shipping', 'marketing_consent', 'marketing_consent_text'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRegionOrders();
        $this->captureDatabaseFingerprint();
    }

    protected function tearDown(): void
    {
        foreach (['orders', 'order_placement_snapshots'] as $table) {
            if (! Schema::hasColumn($table, 'marketing_consent_text')) {
                $this->migration()->up();
                break;
            }
        }

        $this->databaseMatchesFingerprint();
        parent::tearDown();
        $this->skipRemigrationIfClean();
    }

    private function migration(): Migration
    {
        return require base_path(self::PATH);
    }

    public function test_the_columns_are_nullable_with_the_stated_types_on_both_tables(): void
    {
        foreach (['orders', 'order_placement_snapshots'] as $table) {
            $columns = collect(Schema::getColumns($table))->keyBy('name');

            foreach (self::COLUMNS as $column) {
                $this->assertTrue($columns[$column]['nullable'], "{$table}.{$column}");
                $this->assertNull($columns[$column]['default'], "{$table}.{$column}: NULL means not asked, never a defaulted false");
            }

            $this->assertSame('varchar(32)', $columns['terms_version']['type']);
            $this->assertSame('varchar(500)', $columns['marketing_consent_text']['type']);
            $this->assertSame('tinyint(1)', $columns['call_before_shipping']['type']);
            $this->assertStringStartsWith('timestamp', $columns['terms_accepted_at']['type']);
        }
    }

    public function test_every_existing_row_stays_null_and_the_identifiers_are_short(): void
    {
        $this->checkout($this->streetFields('BG'))->assertStatus(201);

        foreach (['orders', 'order_placement_snapshots'] as $table) {
            foreach (self::COLUMNS as $column) {
                $this->assertNull(DB::table($table)->value($column), "{$table}.{$column}");
            }
        }

        foreach (['orders' => 'ord', 'order_placement_snapshots' => 'ops'] as $table => $prefix) {
            foreach (array_keys($this->migration()::checks($prefix)) as $name) {
                $this->assertLessThanOrEqual(64, strlen($name));
                $this->assertTrue(DB::table('information_schema.table_constraints')->where('table_schema', DB::getDatabaseName())->where('table_name', $table)->where('constraint_name', $name)->exists(), $name);
            }
        }
    }

    public function test_the_database_refuses_half_a_terms_proof_and_a_consent_sentence_that_does_not_match_the_consent(): void
    {
        $this->checkout($this->streetFields('BG'))->assertStatus(201);

        foreach (['orders' => 'ord', 'order_placement_snapshots' => 'ops'] as $table => $prefix) {
            $this->assertRefused(fn () => DB::table($table)->update(['terms_version' => '2026-10-14']), "{$prefix}_terms_proof_check");
            $this->assertRefused(fn () => DB::table($table)->update(['terms_accepted_at' => now()]), "{$prefix}_terms_proof_check");
            $this->assertRefused(fn () => DB::table($table)->update(['terms_accepted_at' => now(), 'terms_version' => '  ']), "{$prefix}_terms_proof_check");
            $this->assertRefused(fn () => DB::table($table)->update(['marketing_consent' => 1]), "{$prefix}_consent_text_check");
            $this->assertRefused(fn () => DB::table($table)->update(['marketing_consent' => 0, 'marketing_consent_text' => 'x']), "{$prefix}_consent_text_check");
            $this->assertRefused(fn () => DB::table($table)->update(['marketing_consent_text' => 'x']), "{$prefix}_consent_text_check");
            $this->assertRefused(fn () => DB::table($table)->update(['marketing_consent' => 1, 'marketing_consent_text' => ' ']), "{$prefix}_consent_text_check");

            // The legal shapes.
            DB::table($table)->update(['terms_accepted_at' => now(), 'terms_version' => '2026-10-14', 'marketing_consent' => 1, 'marketing_consent_text' => 'Subscribe me']);
            DB::table($table)->update(['terms_accepted_at' => null, 'terms_version' => null, 'marketing_consent' => 0, 'marketing_consent_text' => null]);
        }
    }

    public function test_a_second_run_changes_nothing_and_down_then_up_restores_the_schema(): void
    {
        $this->migration()->up();
        $this->assertTrue(Schema::hasColumn('orders', 'marketing_consent_text'));

        $this->migration()->down();
        foreach (['orders', 'order_placement_snapshots'] as $table) {
            foreach (self::COLUMNS as $column) {
                $this->assertFalse(Schema::hasColumn($table, $column), "{$table}.{$column}");
            }
        }

        $this->migration()->down();

        $this->migration()->up();
        foreach (['orders', 'order_placement_snapshots'] as $table) {
            foreach (self::COLUMNS as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "{$table}.{$column}");
            }
        }
    }

    private function assertRefused(callable $write, string $check): void
    {
        try {
            $write();
            $this->fail("expected {$check} to refuse the row");
        } catch (QueryException $e) {
            $this->assertSame(3819, $e->errorInfo[1]);
            $this->assertStringContainsString($check, $e->getMessage());
        }
    }
}
