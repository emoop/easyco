<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P6b: «Замества ръчната промо цена». OFF (the default, exactly today's behaviour): a variation that has a Manual Sale price keeps it, the campaign does not touch it.
     * ON: for a variation that has BOTH a Manual Sale price and a price from this campaign, the customer gets the LOWER of the two. Only meaningful for a percent-off campaign; every existing list is OFF.
     */
    public function up(): void
    {
        Schema::table('pricing_price_lists', function (Blueprint $table) {
            $table->boolean('replaces_manual_sale')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('pricing_price_lists', function (Blueprint $table) {
            $table->dropColumn('replaces_manual_sale');
        });
    }
};
