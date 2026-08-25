<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_sales_installment_plans', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')
                ->constrained('operational_sales_clients', indexName: 'os_installment_plans_client_id_foreign')
                // Restrict, not cascade: a Client row must never be
                // hard-deleted out from under a plan that still
                // references it — mirrors catalog_variations'
                // product_id -> catalog_products FK.
                ->restrictOnDelete();

            // active | completed | cancelled — see
            // EasyCo\OperationalSales\Enums\InstallmentPlanStatus.
            $table->string('status')->default('active');

            $table->timestamps();

            // The hot lookup path: "does this client have an active plan
            // right now" — InstallmentPlan's core invariant check
            // (operational-sales-domain-design.md §2/§3.10).
            $table->index(['client_id', 'status'], 'os_installment_plans_client_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_sales_installment_plans');
    }
};
