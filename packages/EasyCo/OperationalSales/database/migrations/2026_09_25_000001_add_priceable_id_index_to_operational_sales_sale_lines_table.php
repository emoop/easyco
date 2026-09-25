<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * catalog-domain-design.md §3.19.5, rule 3 — the ONE schema change the
     * deletion design requires.
     *
     * The design's history check is a LOCKING read (`SELECT id FROM
     * operational_sales_sale_lines WHERE priceable_id = ? FOR UPDATE`),
     * because a plain snapshot read under MySQL's default REPEATABLE READ
     * can miss a sale line committed by a concurrent checkout after the
     * transaction's snapshot was established — the exact race that would
     * let a variation with history be deleted. `priceable_id` has no index
     * today (the table's other indexes cover `transaction_id`,
     * `(client_id, type, status)`, `installment_plan_id` and the FKs), so
     * that locking read would scan — and lock — the whole table; with this
     * index it locks only this variation's own rows and their index
     * neighbours. The same index is what makes the count cheap.
     *
     * A plain, NON-unique index: one variation legitimately appears on many
     * sale lines (every sale of it, plus its refunds and reservations), and
     * `priceable_id` is NULL for SHIPPING / INSTALLMENT_PAYMENT pseudo-lines
     * — see that column's own docblock on the table's create migration.
     *
     * Explicit short index name (prefix `os_sale_lines_`), per CLAUDE.md
     * rule 5: the table name is long enough that an auto-generated name is
     * a standing identifier-length risk.
     */
    public function up(): void
    {
        Schema::table('operational_sales_sale_lines', function (Blueprint $table) {
            $table->index('priceable_id', 'os_sale_lines_priceable_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('operational_sales_sale_lines', function (Blueprint $table) {
            $table->dropIndex('os_sale_lines_priceable_id_index');
        });
    }
};
