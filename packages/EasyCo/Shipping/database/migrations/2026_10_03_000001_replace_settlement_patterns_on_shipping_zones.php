<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * shipping-domain-design.md §4 (stage 3.0a): the single free-form
     * `settlement_patterns` list is replaced by two explicit lists,
     * `settlement_names` and `postcodes` (JSON, nullable). The old list mixed
     * names and postcodes and nothing could tell them apart.
     *
     * A GATE, NOT A GUESS (same posture as guard_no_fulfilled_orders): up()
     * refuses to run while ANY zone still holds settlement_patterns, naming
     * the zone ids. Which entries were postcodes and which were names is not
     * something a migration can know, so it never splits the list on its own;
     * the merchant moves each entry by hand (and clears the old column), then
     * re-runs. A deployment with no patterns passes straight through.
     *
     * down() restores the old (empty) column, but only if neither new column
     * holds data: folding two lists back into one would silently re-create the
     * ambiguity this migration removes, so it refuses instead.
     */
    public function up(): void
    {
        $ids = DB::table('shipping_zones')->whereNotNull('settlement_patterns')->orderBy('id')->pluck('id');

        if ($ids->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'shipping_zones.settlement_patterns still holds data in %d %s (shipping_zones.id: %s). '
                .'It is replaced by settlement_names and postcodes, and a migration cannot know which entries are postcodes. '
                .'Nothing was changed; move each entry by hand, clear the column, and re-run.',
                $ids->count(),
                $ids->count() === 1 ? 'zone' : 'zones',
                $ids->take(20)->implode(', ').($ids->count() > 20 ? ', …' : ''),
            ));
        }

        // ADD FIRST, DROP LAST: MySQL's DDL is not transactional (CLAUDE.md rule
        // 6), so if the second statement failed, the old column and its (guarded
        // empty) data must still be there, never a table with neither.
        Schema::table('shipping_zones', function (Blueprint $table) {
            $table->json('settlement_names')->nullable()->after('country_codes');
            $table->json('postcodes')->nullable()->after('settlement_names');
        });

        Schema::table('shipping_zones', function (Blueprint $table) {
            $table->dropColumn('settlement_patterns');
        });
    }

    public function down(): void
    {
        $ids = DB::table('shipping_zones')
            ->where(fn ($query) => $query->whereNotNull('settlement_names')->orWhereNotNull('postcodes'))
            ->orderBy('id')
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'shipping_zones.settlement_names / postcodes hold data in %d %s (shipping_zones.id: %s). '
                .'Rolling back would have to merge two lists into one and re-create the ambiguity this migration removed. '
                .'Nothing was changed; clear both columns by hand first.',
                $ids->count(),
                $ids->count() === 1 ? 'zone' : 'zones',
                $ids->take(20)->implode(', ').($ids->count() > 20 ? ', …' : ''),
            ));
        }

        // Same order in reverse: add the old column first, drop the two new ones last.
        Schema::table('shipping_zones', function (Blueprint $table) {
            $table->json('settlement_patterns')->nullable()->after('country_codes');
        });

        Schema::table('shipping_zones', function (Blueprint $table) {
            $table->dropColumn(['settlement_names', 'postcodes']);
        });
    }
};
