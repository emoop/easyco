<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether THIS product's attached video should autoplay on the
     * (not-yet-built) storefront — a real, per-attachment display
     * behavior, not a substitute for ProductMedia's own deliberate "no
     * is_primary field" decision (see that class's docblock): autoplay
     * has nothing to do with ordering/which-photo-is-main, so it
     * doesn't reopen that decision. Lives on the pivot, not on
     * MediaAsset itself, because the same video could in principle be
     * attached to more than one product, each with its own autoplay
     * preference — media-domain-design.md §2.1 already establishes
     * ProductMedia as the per-attachment record.
     */
    public function up(): void
    {
        Schema::table('catalog_product_media', function (Blueprint $table) {
            $table->boolean('autoplay')->default(false)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_product_media', function (Blueprint $table) {
            $table->dropColumn('autoplay');
        });
    }
};
