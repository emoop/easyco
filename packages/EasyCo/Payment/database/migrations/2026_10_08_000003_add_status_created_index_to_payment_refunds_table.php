<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Refunds R4a-1 (shipping-domain-design.md §7.2.20 §6): "needs attention" lists OWED refunds by
     * age, oldest first — a read by status ordered by created_at, for which payment_refunds had no
     * index. A plain index: it loses nothing on rollback.
     */
    public function up(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'pay_refunds_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->dropIndex('pay_refunds_status_created_idx');
        });
    }
};
