<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * zone_id is ON DELETE RESTRICT: a zone that still has methods cannot be
     * removed from under them (the application has no zone delete in this
     * stage; the constraint is the backstop for any path that would try).
     *
     * Money columns are signed bigInteger minor units, the same type as
     * orders.subtotal_minor/discount_minor/total_minor. The per-kind rules
     * (which of amount/class rates/carrier code each kind allows) live in the
     * ShippingMethod domain class, as with addresses' delivery_type fields:
     * there is no portable DB constraint for conditional nullability.
     */
    public function up(): void
    {
        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();

            $table->foreignId('zone_id')
                ->constrained('shipping_zones', indexName: 'ship_methods_zone_id_foreign')
                ->restrictOnDelete();

            $table->string('name');
            // flat | free | per_class | carrier — EasyCo\Shipping\Enums\ShippingMethodKind.
            $table->string('kind');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->bigInteger('amount_minor')->nullable();
            $table->bigInteger('free_above_minor')->nullable();
            $table->string('carrier_code', 64)->nullable();
            $table->boolean('requires_pickup_point')->default(false);

            $table->timestamps();

            $table->index(['zone_id', 'sort_order'], 'ship_methods_zone_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_methods');
    }
};
