<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * order-editing-design.md §2.2 (edit_revision) and §3 (tracking_number)
     * — two new, unused-until-later-stages columns on orders itself.
     *
     * edit_revision: unsignedInteger, default 0. "0" means "never edited"
     * for every pre-existing row too — reconstructible without a query,
     * since order_placement_snapshots and orders agree exactly when
     * edit_revision = 0 (no backfill needed, the default IS the honest
     * fact for every row placed before this stage).
     *
     * tracking_number: nullable string, no backfill possible or attempted
     * — no data exists to backfill from.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('edit_revision')->default(0);
            $table->string('tracking_number')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['edit_revision', 'tracking_number']);
        });
    }
};
