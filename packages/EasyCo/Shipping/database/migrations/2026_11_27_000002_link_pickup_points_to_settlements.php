<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * COUR-1b: what COUR-1a left for here (see 2026_11_26_000001_create_shipping_pickup_points_table).
 *  - `courier_settlement_ref` (varchar 32, NULL): the courier's own settlement id of the point (Speedy `siteId`, Econt `city.id`), the
 *    key the sync joins to `shipping_settlements` (§3.3 item 5). BOX NOW has none; its lockers are mapped by postcode + name.
 *    Index `spp_settlement_ref_idx (carrier_code, courier_settlement_ref)`.
 *  - the foreign key `spp_settlement_fk` on `settlement_id` -> `shipping_settlements.id`, restricting (no ON DELETE action: a settlement
 *    is never deleted). Every row written until now has NULL there, so it can be added without touching a row.
 * No country column: points are Bulgarian for now (the sync is `BG`), and a point's country is its settlement's. Idempotent (rule 6).
 */
return new class extends Migration
{
    private const TABLE = 'shipping_pickup_points';

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'courier_settlement_ref')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->string('courier_settlement_ref', 32)->nullable()->after('settlement_id');
                $table->index(['carrier_code', 'courier_settlement_ref'], 'spp_settlement_ref_idx');
            });
        }

        if (! $this->hasForeignKey()) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->foreign('settlement_id', 'spp_settlement_fk')->references('id')->on('shipping_settlements');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasForeignKey()) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropForeign('spp_settlement_fk');
            });
        }

        if (Schema::hasColumn(self::TABLE, 'courier_settlement_ref')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropIndex('spp_settlement_ref_idx');
                $table->dropColumn('courier_settlement_ref');
            });
        }
    }

    private function hasForeignKey(): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('CONSTRAINT_NAME', 'spp_settlement_fk')
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
