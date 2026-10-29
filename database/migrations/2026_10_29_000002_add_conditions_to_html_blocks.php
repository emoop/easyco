<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HB-2: `html_blocks.conditions`, a nullable JSON object («Показвай, когато…»: on sale, categories, tags, seasons, attributes). Null = no conditions: the block is shown everywhere, as before.
     * Existing rows stay null. Idempotent (CLAUDE.md rule 6): a column that is already there is left alone.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('html_blocks', 'conditions')) {
            Schema::table('html_blocks', function (Blueprint $table) {
                $table->json('conditions')->nullable()->after('style');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('html_blocks', 'conditions')) {
            Schema::table('html_blocks', function (Blueprint $table) {
                $table->dropColumn('conditions');
            });
        }
    }
};
