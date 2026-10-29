<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HB-1: `html_blocks.style`, a nullable JSON object (the «Стил на блока»: background, text colour, padding, border, radius, width, alignment, size). Null = no style: the block prints exactly as
     * before, without a wrapper. Existing rows stay null. Idempotent (CLAUDE.md rule 6): a column that is already there is left alone.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('html_blocks', 'style')) {
            Schema::table('html_blocks', function (Blueprint $table) {
                $table->json('style')->nullable()->after('pictures');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('html_blocks', 'style')) {
            Schema::table('html_blocks', function (Blueprint $table) {
                $table->dropColumn('style');
            });
        }
    }
};
