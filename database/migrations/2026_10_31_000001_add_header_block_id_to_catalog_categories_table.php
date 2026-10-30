<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CD-1b: «Блок отгоре» — a category may show an HTML block (the merchant's banner) full width above its list. The id is nullable; the foreign key RESTRICTS the delete of a block that is in use (no `nullOnDelete`:
 * the delete is refused with a message instead, see HtmlBlockUsage). Short explicit names (the 64-character limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('catalog_categories', 'header_block_id')) {
            return;
        }

        Schema::table('catalog_categories', function (Blueprint $table): void {
            $table->unsignedBigInteger('header_block_id')->nullable()->after('slug');
            $table->foreign('header_block_id', 'catalog_categories_header_block_fk')->references('id')->on('html_blocks')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('catalog_categories', 'header_block_id')) {
            return;
        }

        Schema::table('catalog_categories', function (Blueprint $table): void {
            $table->dropForeign('catalog_categories_header_block_fk');
            $table->dropColumn('header_block_id');
        });
    }
};
