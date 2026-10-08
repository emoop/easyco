<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The admin sidebar's "Поръчки" badge asks `select count(*) from orders where status = ?` on
     * every page render (OrderResource::getNavigationBadge()), and orders.status — a varchar(255)
     * with no index at all — made each of those a full table scan.
     *
     * A PLAIN, non-unique index: many orders legitimately share one status, so there is nothing to
     * make unique, and this migration changes NO data — no column, no row, no backfill. It loses
     * nothing on rollback either.
     *
     * Named explicitly (CLAUDE.md rule 5): `ord_status_idx` is 14 characters, matching this
     * package's own short `ord_*` prefix, instead of leaning on a generated name.
     *
     * Idempotent on purpose (CLAUDE.md rule 6): MySQL's DDL is not transactional, so a migration
     * that failed half-way can be re-run — an index that is already there is left alone, and the
     * same guard protects the rollback.
     */
    public function up(): void
    {
        if (Schema::hasIndex('orders', 'ord_status_idx')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->index('status', 'ord_status_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('orders', 'ord_status_idx')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('ord_status_idx');
        });
    }
};
