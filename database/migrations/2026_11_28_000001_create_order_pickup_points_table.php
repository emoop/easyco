<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `order_pickup_points` — one row per order that was placed with a point CHOSEN FROM THE COURIER'S LIST (COUR-2a,
     * courier-integration-design.md §4.5). An APP-LEVEL context table (beside `order_attributions`): the Order package knows nothing of it.
     *
     * The order already stores the courier code, the courier's reference, the name, the address line, the kind and the settlement it was
     * quoted for. What it has no place for is OUR point id and the point's postcode, and the settlement name AS THE COURIER WRITES IT;
     * this row holds those, plus a copy of the rest taken at the same instant, so one row reads whole. It is a SNAPSHOT: a later sync that
     * renames, moves or deactivates the point never changes it.
     *
     * `point_id` has NO foreign key on purpose (a snapshot outlives the table it was copied from; points are never deleted today, but
     * this row must not be what makes that a rule). `order_id` is a RESTRICT foreign key plus a unique key: at most one per order.
     * The retention anonymisation (OrderAnonymiser) blanks every descriptive column and the point id.
     *
     * CLAUDE.md rule 5: explicit short names. Rule 6: idempotent, a table or CHECK that is already there is left alone.
     */
    private const CHECK = 'opp_kind_check';

    public function up(): void
    {
        if (! Schema::hasTable('order_pickup_points')) {
            Schema::create('order_pickup_points', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders', indexName: 'opp_order_id_foreign')->restrictOnDelete();
                $table->unique('order_id', 'opp_order_id_unique');

                $table->unsignedBigInteger('point_id')->nullable();
                $table->string('carrier_code', 32);
                $table->string('external_ref', 64)->nullable();
                $table->string('kind', 10);
                $table->string('name', 255)->nullable();
                $table->string('address_line', 255)->nullable();
                $table->string('settlement_name', 120)->nullable();
                $table->string('postcode', 12)->nullable();
                $table->timestamp('created_at', 6)->nullable();
            });
        }

        $has = DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?', ['order_pickup_points', self::CHECK]);

        if ((int) ($has->n ?? 0) === 0) {
            DB::statement("ALTER TABLE order_pickup_points ADD CONSTRAINT opp_kind_check CHECK (kind COLLATE utf8mb4_bin IN ('office','locker'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_pickup_points');
    }
};
