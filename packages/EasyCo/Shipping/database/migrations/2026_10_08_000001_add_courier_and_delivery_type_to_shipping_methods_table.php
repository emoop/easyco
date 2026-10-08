<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Courier grouping (shipping stage 5f): two OPTIONAL display fields on a method.
     *  - `courier`: the group's display name ("Econt", "Speedy"); groups are matched by the trimmed, lower-cased name
     *    (ShippingCourier::key()), so no unique or index is needed;
     *  - `delivery_type`: address | office | locker | other, guarded by a CHECK (MySQL/MariaDB, the supportsCheck()
     *    pattern; the name is explicit and short, CLAUDE.md rule 5).
     * A pure add: every existing method keeps NULL in both = ungrouped = exactly today's behaviour, no data change.
     */
    public function up(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->string('courier', 100)->nullable()->after('name');
            $table->string('delivery_type', 10)->nullable()->after('courier');
        });

        if ($this->supportsCheck()) {
            DB::statement("ALTER TABLE shipping_methods ADD CONSTRAINT ship_methods_delivery_type_check CHECK (delivery_type IS NULL OR delivery_type IN ('address', 'office', 'locker', 'other'))");
        }
    }

    public function down(): void
    {
        if ($this->supportsCheck()) {
            DB::statement('ALTER TABLE shipping_methods DROP CONSTRAINT ship_methods_delivery_type_check');
        }

        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->dropColumn(['courier', 'delivery_type']);
        });
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
