<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * operational-sales-domain-design.md §3.12 — a name/SKU snapshot,
     * required only for SaleLineType::SALE, null for every other type
     * (see SaleLine::assertProductNameAndSkuMatchType()).
     */
    public function up(): void
    {
        Schema::table('operational_sales_sale_lines', function (Blueprint $table) {
            $table->string('product_name')->nullable()->after('priceable_id');
            $table->string('sku')->nullable()->after('product_name');
        });
    }

    public function down(): void
    {
        Schema::table('operational_sales_sale_lines', function (Blueprint $table) {
            $table->dropColumn(['product_name', 'sku']);
        });
    }
};
