<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CT-3: the filter look «buttons» (CT-2) is replaced by «links» (plain text links on one line); the stored values follow. `down()` puts them back as `buttons` (every `links` row: the old set has
 * no other use of the word).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('catalog_attribute_definitions')->where('filter_display', 'buttons')->update(['filter_display' => 'links']);
    }

    public function down(): void
    {
        DB::table('catalog_attribute_definitions')->where('filter_display', 'links')->update(['filter_display' => 'buttons']);
    }
};
