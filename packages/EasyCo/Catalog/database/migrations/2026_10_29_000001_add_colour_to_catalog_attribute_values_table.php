<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AT-1: a value of an attribute may have a colour (`#rrggbb`, lower-case, nullable) for the round swatches of a «swatch» attribute. Presentation only: it is never part of a variation's identity. Existing
     * values keep null. (The attribute's own display type already exists since CT-3: `catalog_attribute_definitions.display_type`, default dropdown.) Idempotent (CLAUDE.md rule 6).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('catalog_attribute_values', 'colour')) {
            Schema::table('catalog_attribute_values', function (Blueprint $table) {
                $table->string('colour', 7)->nullable()->after('picture_media_asset_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('catalog_attribute_values', 'colour')) {
            Schema::table('catalog_attribute_values', fn (Blueprint $table) => $table->dropColumn('colour'));
        }
    }
};
