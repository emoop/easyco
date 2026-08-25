<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enforces "at most one ACTIVE InstallmentPlan per client" at the DB
     * level — the exact TOCTOU race the DB-constraint-first pattern
     * (catalog-domain-design.md §"Variation combination uniqueness")
     * exists to prevent, applied here: two concurrent operators opening a
     * second active plan for the same client.
     *
     * Same technique already proven for catalog_variations.sku/barcode:
     * a derived marker column, NULL except in the one state that needs
     * uniqueness (ACTIVE), backed by a plain UNIQUE index. Both
     * MySQL/MariaDB and SQLite treat multiple NULLs in a unique index as
     * distinct, so this gives "unique only while active" semantics
     * without a partial/filtered index — which MySQL doesn't support
     * anyway. EloquentInstallmentPlanRepository::save() is what actually
     * maintains this column (active_client_id = client_id when
     * status=active, null otherwise) — see that class. The domain
     * InstallmentPlan itself has no concept of this column at all: it's
     * a pure persistence-layer projection of status, the same way
     * catalog_variations.attribute_signature is a projection of
     * attribute_assignments, never a source of truth application code
     * reasons about directly.
     *
     * Explicit short names throughout ("os_installment_plans_..." rather
     * than the full table name): operational_sales_installment_plans is
     * 36 characters, and this column's auto-generated FK/unique names
     * would land at 60/59 characters — under MySQL's 64-character limit,
     * but close enough to the edge that this project's own convention
     * (catalog-domain-design.md §7 / CLAUDE.md rule 5) is to name it
     * explicitly rather than trust the margin.
     */
    public function up(): void
    {
        Schema::table('operational_sales_installment_plans', function (Blueprint $table) {
            $table->foreignId('active_client_id')->nullable()
                ->constrained('operational_sales_clients', indexName: 'os_installment_plans_active_client_id_foreign')
                ->restrictOnDelete();
        });

        // Backfill: any pre-existing ACTIVE row (from before this column
        // existed) must have active_client_id populated to actually
        // participate in the uniqueness guarantee going forward.
        DB::table('operational_sales_installment_plans')
            ->where('status', 'active')
            ->update(['active_client_id' => DB::raw('client_id')]);

        Schema::table('operational_sales_installment_plans', function (Blueprint $table) {
            $table->unique('active_client_id', 'os_installment_plans_active_client_id_unique');
        });
    }

    public function down(): void
    {
        // The FK must be dropped BEFORE the unique index: MySQL requires
        // a supporting index for every FK, and active_client_id has no
        // other index besides this UNIQUE one, so dropping the index
        // first fails with error 1553 ("needed in a foreign key
        // constraint") — confirmed by actually running this rollback
        // against the real dev database, not assumed.
        Schema::table('operational_sales_installment_plans', function (Blueprint $table) {
            // Explicit names, not dropConstrainedForeignId()'s default
            // naming convention — this FK/index were created with custom
            // short names above, not the auto-generated ones.
            $table->dropForeign('os_installment_plans_active_client_id_foreign');
        });

        Schema::table('operational_sales_installment_plans', function (Blueprint $table) {
            $table->dropUnique('os_installment_plans_active_client_id_unique');
            $table->dropColumn('active_client_id');
        });
    }
};
