<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CT-2: an attribute may be offered as a shop filter (`is_filterable`, default no) and says how its values are shown in one
     * (`filter_display`: list | buttons | swatches, default list). Nothing reads them on the storefront yet: existing rows get the
     * defaults, so nothing changes for anyone. Idempotent (CLAUDE.md rule 6): a column that is already there is left alone.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('catalog_attribute_definitions', 'is_filterable')) {
            Schema::table('catalog_attribute_definitions', function (Blueprint $table) {
                $table->boolean('is_filterable')->default(false)->after('type');
            });
        }

        if (! Schema::hasColumn('catalog_attribute_definitions', 'filter_display')) {
            Schema::table('catalog_attribute_definitions', function (Blueprint $table) {
                $table->string('filter_display', 16)->default('list')->after('is_filterable');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('catalog_attribute_definitions', 'filter_display')) {
            Schema::table('catalog_attribute_definitions', fn (Blueprint $table) => $table->dropColumn('filter_display'));
        }

        if (Schema::hasColumn('catalog_attribute_definitions', 'is_filterable')) {
            Schema::table('catalog_attribute_definitions', fn (Blueprint $table) => $table->dropColumn('is_filterable'));
        }
    }
};
