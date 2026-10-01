<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * country_codes / settlement_patterns are JSON lists. The repository writes
     * them with JSON_UNESCAPED_UNICODE so Cyrillic is stored as itself, never as
     * \uXXXX escapes. sort_order is NOT unique (several zones may share one);
     * ordering is always `sort_order, id`, hence the index on sort_order.
     */
    public function up(): void
    {
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('country_codes');
            $table->json('settlement_patterns')->nullable();

            $table->timestamps();

            $table->index('sort_order', 'ship_zones_sort_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_zones');
    }
};
