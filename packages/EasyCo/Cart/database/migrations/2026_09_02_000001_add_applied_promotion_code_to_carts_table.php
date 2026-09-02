<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A plain string, not a foreign key — mirrors
     * EasyCo\Promotions\PromotionScope::scope_reference_id's own
     * deliberate non-FK convention. No unique constraint either: this
     * column stores one cart's currently-applied code, not a
     * cross-domain identity anything else references. Domain +
     * persistence only in this pass — no HTTP surface, no
     * PromotionValidator wiring, no discount computation, no
     * live-revalidation logic yet.
     */
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->string('applied_promotion_code')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('applied_promotion_code');
        });
    }
};
