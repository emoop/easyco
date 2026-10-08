<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * orders.status carries the index the admin sidebar's badge count needs
 * (OrderResource::getNavigationBadge(): `select count(*) from orders where status = ?`), and the
 * migration that adds it is safe to run again on a half-applied state — CLAUDE.md rule 6: MySQL's
 * DDL is not transactional, so a failed migration leaves partial state behind and must be
 * re-runnable.
 */
class OrdersStatusIndexTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'packages/EasyCo/Order/database/migrations/2026_10_09_000002_add_status_index_to_orders_table.php';

    /** The migration exactly as the migrator loads it: the file returns an anonymous Migration. */
    private function migration(): Migration
    {
        return require base_path(self::MIGRATION);
    }

    public function test_the_orders_table_carries_a_plain_non_unique_index_on_status(): void
    {
        $indexes = collect(Schema::getIndexes('orders'))->keyBy('name');

        $this->assertTrue($indexes->has('ord_status_idx'), 'the badge count needs an index on orders.status');

        $index = $indexes->get('ord_status_idx');

        $this->assertSame(['status'], $index['columns'], 'on exactly the column the badge filters by');
        $this->assertFalse($index['unique'], 'a plain index: many orders share a status');
        $this->assertFalse($index['primary']);
    }

    public function test_running_the_migration_again_on_a_half_applied_state_is_a_no_op_not_an_error(): void
    {
        // The suite has already migrated, so the index is there: up() must skip it rather than
        // throw "Duplicate key name 'ord_status_idx'" — which is what re-running a half-applied
        // migration (rule 6) looks like in practice.
        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(
            1,
            collect(Schema::getIndexes('orders'))->where('name', 'ord_status_idx')->count(),
            'still exactly one index of that name',
        );
    }
}
