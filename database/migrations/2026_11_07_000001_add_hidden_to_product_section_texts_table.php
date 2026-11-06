<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P4c: the per-product switch «Показвай на този продукт» of a product section: `hidden` = the section is not shown on THIS product, whatever its source. Only the rows that differ from the default are stored
     * (shown + no text = no row), so a hidden section can exist without a text and a hidden section keeps its text. Idempotent (CLAUDE.md rule 6): a column that is already there is left alone.
     */
    public function up(): void
    {
        if (! Schema::hasTable('product_section_texts') || Schema::hasColumn('product_section_texts', 'hidden')) {
            return;
        }

        Schema::table('product_section_texts', function (Blueprint $table) {
            $table->boolean('hidden')->default(false)->after('body');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('product_section_texts') && Schema::hasColumn('product_section_texts', 'hidden')) {
            Schema::table('product_section_texts', function (Blueprint $table) {
                $table->dropColumn('hidden');
            });
        }
    }
};
