<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds catalog_variations.sort_order — the merchant's own DISPLAY ORDER
 * for a product's variations, set by dragging rows in
 * EditVariableProduct's "Variations" tab (admin-panel-design.md §13.6).
 *
 * Deliberately NOT a domain field on EasyCo\Catalog\Variation: order is a
 * merchandising concern, not a business fact about a sellable
 * configuration — the exact same category as the media pivots'
 * (catalog_product_media/catalog_variation_media) own sort_order column,
 * which the Variation/ProductMedia domain objects don't carry either. It
 * is written by EloquentVariationRepository::updateSortOrders() and read
 * back ordered by EloquentProductRepository::findByIdWithVariations() /
 * EloquentVariationRepository::findByProductId().
 *
 * Default 0 (not nullable, no DB-level uniqueness): a row that has never
 * been reordered sits at 0 alongside any other never-reordered row, and
 * the read paths break that tie by id ASC — which is exactly the order
 * every pre-existing row was already displayed in, so this migration is
 * behavior-preserving on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_variations', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('product_id');

            // Explicit short name — CLAUDE.md rule 5 (MySQL/MariaDB's
            // 64-char identifier limit); the read paths order by exactly
            // this pair.
            $table->index(['product_id', 'sort_order'], 'catalog_variations_product_sort_index');
        });

        // Backfill: every pre-existing variation keeps the order it is
        // already displayed in today (creation/id order), renumbered
        // 0..n-1 per product. The column was just added with a uniform
        // default of 0, so this is a plain renumber, not a diff — and it
        // runs through the plain query builder (not Eloquent) precisely
        // so the mechanical backfill does NOT bump updated_at on every
        // existing row.
        //
        // One bounded read (two small integer columns) plus n updates;
        // chunking isn't used deliberately: DB::table()->chunk() would
        // need a different ordering column than the (product_id, id) pair
        // this backfill's own numbering depends on, and a streaming
        // cursor() cannot safely run the updates concurrently on MySQL.
        $currentProductId = null;
        $position = 0;

        $rows = DB::table('catalog_variations')
            ->select('id', 'product_id')
            ->orderBy('product_id')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if ($row->product_id !== $currentProductId) {
                $currentProductId = $row->product_id;
                $position = 0;
            }

            DB::table('catalog_variations')
                ->where('id', $row->id)
                ->update(['sort_order' => $position]);

            $position++;
        }
    }

    public function down(): void
    {
        Schema::table('catalog_variations', function (Blueprint $table) {
            $table->dropIndex('catalog_variations_product_sort_index');
            $table->dropColumn('sort_order');
        });
    }
};
