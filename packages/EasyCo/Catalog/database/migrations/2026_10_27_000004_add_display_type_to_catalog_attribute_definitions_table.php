<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CT-3: an attribute says how its values are shown on the product page and in the card (`display_type`: swatch | buttons | dropdown | specs,
     * default dropdown). Nothing reads it on the storefront yet: existing rows get the default, so nothing changes for anyone. Idempotent (CLAUDE.md rule 6).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('catalog_attribute_definitions', 'display_type')) {
            Schema::table('catalog_attribute_definitions', function (Blueprint $table) {
                $table->string('display_type', 16)->default('dropdown')->after('filter_display');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('catalog_attribute_definitions', 'display_type')) {
            Schema::table('catalog_attribute_definitions', fn (Blueprint $table) => $table->dropColumn('display_type'));
        }
    }
};
