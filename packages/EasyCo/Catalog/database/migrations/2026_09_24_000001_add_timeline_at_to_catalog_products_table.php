<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds catalog_products.timeline_at — the product's EFFECTIVE position
 * in the merchant-facing product timeline: equal to created_at unless
 * explicitly promoted (Product::promote()/unpromote() — see
 * catalog-domain-design.md's own "Product timeline" section). A plain,
 * always-populated NOT NULL column, never nullable — ordering is a
 * single `ORDER BY timeline_at DESC, id DESC`, no COALESCE, no second
 * "is this promoted" source of truth.
 *
 * Same safe three-step pattern already established twice in this
 * package for a new NOT NULL column on an existing table (
 * 2026_08_23_000014_add_base_sku_to_catalog_products_table.php,
 * 2026_08_23_000015_make_catalog_variations_sku_not_null.php):
 * a) add nullable, b) backfill, c) tighten to NOT NULL — then d) add
 * the composite index the default sort relies on.
 *
 * SAME DRIVER FOR TESTS AND PRODUCTION — no dual-driver handling
 * needed here (unlike some earlier Catalog migrations written when
 * tests ran against SQLite): this app's phpunit run resolves
 * DB_CONNECTION=mysql/DB_DATABASE=easyco_testing via .env.testing, the
 * same MySQL/MariaDB driver production uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->timestamp('timeline_at')->nullable()->after('created_at');
        });

        DB::table('catalog_products')->update(['timeline_at' => DB::raw('created_at')]);

        Schema::table('catalog_products', function (Blueprint $table) {
            $table->timestamp('timeline_at')->nullable(false)->change();
        });

        Schema::table('catalog_products', function (Blueprint $table) {
            // Explicit short name — CLAUDE.md rule 5. Backs the admin
            // panel's own default sort (ProductResource::table()'s
            // ->defaultSort('timeline_at', 'desc')) — Filament's own
            // hasDefaultKeySort() (default true, confirmed against the
            // installed v5.8.1 source) appends the id DESC tie-break
            // automatically, so this index's own (timeline_at, id)
            // column order matches exactly what that combined sort
            // actually executes.
            $table->index(['timeline_at', 'id'], 'catalog_products_timeline_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->dropIndex('catalog_products_timeline_id_index');
            $table->dropColumn('timeline_at');
        });
    }
};
