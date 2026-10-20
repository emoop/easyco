<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MENU-5: `html_blocks.pictures`, a nullable JSON list of up to 4 {media_asset_id, alt, url} («Снимки с връзки»). Existing rows stay null: their body is not touched.
     * Idempotent (CLAUDE.md rule 6): a column that is already there is left alone.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('html_blocks', 'pictures')) {
            Schema::table('html_blocks', function (Blueprint $table) {
                $table->json('pictures')->nullable()->after('body');
            });
        }
    }

    public function down(): void
    {
        // Pictures are content: nothing is dropped on a rollback.
    }
};
