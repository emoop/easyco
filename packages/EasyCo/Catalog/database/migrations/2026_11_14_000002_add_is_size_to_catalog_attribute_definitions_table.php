<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MEAS-2: "size attribute" - the attribute whose values are the ROWS of a product's measurement grid. Default false and NOT NULL, so every existing row and fixture is unchanged;
     * the admin checkbox is MEAS-3. Idempotent (CLAUDE.md rule 6).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('catalog_attribute_definitions', 'is_size')) {
            Schema::table('catalog_attribute_definitions', function (Blueprint $table) {
                $table->boolean('is_size')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('catalog_attribute_definitions', 'is_size')) {
            Schema::table('catalog_attribute_definitions', fn (Blueprint $table) => $table->dropColumn('is_size'));
        }
    }
};
