<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shipping stage 4a (shipping-domain-design.md §9.1.4): the order and its write-once placement snapshot can STORE
     * the shipping facts of the method the customer chose. Storage only — nothing writes these columns yet (stage 4e
     * wires the checkout), so every existing row keeps the honest "no shipping" values.
     *
     *  - `orders` gains `shipping_courier` string(100) NULL, `shipping_delivery_type` string(10) NULL and
     *    `shipping_service_code` string(64) NULL, after the name/code columns stage 2 added.
     *  - `order_placement_snapshots` gains the same three PLUS the three orders already has — `shipping_minor`
     *    (signed bigInteger default 0, exactly orders' type), `shipping_method_name` string(255) NULL and
     *    `shipping_method_code` string(64) NULL — so the snapshot can carry every shipping fact the order does.
     *    Existing snapshot rows get shipping_minor = 0, which is the true fact for every order placed before shipping
     *    existed: the snapshot's total was always subtotal - discount (checked on the dev database before this
     *    migration; the snapshot table has no total CHECK of its own, so there is nothing to extend).
     *  - A CHECK on `shipping_delivery_type` in BOTH tables: NULL or one of address / office / locker / other — the same
     *    list as shipping_methods.delivery_type (ship_methods_delivery_type_check). MySQL/MariaDB only, like the
     *    existing orders_total_formula_check; other drivers keep only the entity's own rule (Order::SHIPPING_DELIVERY_TYPES).
     *    Explicit short names (CLAUDE.md rule 5): `ord_ship_delivery_type_check` is 28 characters and
     *    `ops_ship_delivery_type_check` is 28 characters, both under MySQL's 64.
     *
     * IDEMPOTENT (CLAUDE.md rule 6): MySQL's DDL is not transactional, so a half-applied run can be re-run — a column or
     * a CHECK that is already there is left alone, in up() and in down().
     */
    private const CHECK_ORDERS = 'ord_ship_delivery_type_check';

    private const CHECK_SNAPSHOTS = 'ops_ship_delivery_type_check';

    private const DELIVERY_TYPES = "'address', 'office', 'locker', 'other'";

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'shipping_courier')) {
                $table->string('shipping_courier', 100)->nullable()->after('shipping_method_code');
            }
            if (! Schema::hasColumn('orders', 'shipping_delivery_type')) {
                $table->string('shipping_delivery_type', 10)->nullable()->after('shipping_courier');
            }
            if (! Schema::hasColumn('orders', 'shipping_service_code')) {
                $table->string('shipping_service_code', 64)->nullable()->after('shipping_delivery_type');
            }
        });

        Schema::table('order_placement_snapshots', function (Blueprint $table) {
            if (! Schema::hasColumn('order_placement_snapshots', 'shipping_minor')) {
                $table->bigInteger('shipping_minor')->default(0)->after('discount_currency');
            }
            if (! Schema::hasColumn('order_placement_snapshots', 'shipping_method_name')) {
                $table->string('shipping_method_name', 255)->nullable()->after('shipping_minor');
            }
            if (! Schema::hasColumn('order_placement_snapshots', 'shipping_method_code')) {
                $table->string('shipping_method_code', 64)->nullable()->after('shipping_method_name');
            }
            if (! Schema::hasColumn('order_placement_snapshots', 'shipping_courier')) {
                $table->string('shipping_courier', 100)->nullable()->after('shipping_method_code');
            }
            if (! Schema::hasColumn('order_placement_snapshots', 'shipping_delivery_type')) {
                $table->string('shipping_delivery_type', 10)->nullable()->after('shipping_courier');
            }
            if (! Schema::hasColumn('order_placement_snapshots', 'shipping_service_code')) {
                $table->string('shipping_service_code', 64)->nullable()->after('shipping_delivery_type');
            }
        });

        if ($this->supportsCheck()) {
            $this->addCheck('orders', self::CHECK_ORDERS);
            $this->addCheck('order_placement_snapshots', self::CHECK_SNAPSHOTS);
        }
    }

    /** The checks first, then the columns. */
    public function down(): void
    {
        if ($this->supportsCheck()) {
            $this->dropCheck('orders', self::CHECK_ORDERS);
            $this->dropCheck('order_placement_snapshots', self::CHECK_SNAPSHOTS);
        }

        Schema::table('order_placement_snapshots', function (Blueprint $table) {
            $table->dropColumn(array_values(array_filter(
                ['shipping_service_code', 'shipping_delivery_type', 'shipping_courier', 'shipping_method_code', 'shipping_method_name', 'shipping_minor'],
                fn (string $column): bool => Schema::hasColumn('order_placement_snapshots', $column),
            )));
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(array_values(array_filter(
                ['shipping_service_code', 'shipping_delivery_type', 'shipping_courier'],
                fn (string $column): bool => Schema::hasColumn('orders', $column),
            )));
        });
    }

    private function addCheck(string $table, string $name): void
    {
        if ($this->hasCheck($table, $name)) {
            return;
        }

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK (shipping_delivery_type IS NULL OR shipping_delivery_type IN (".self::DELIVERY_TYPES.'))');
    }

    private function dropCheck(string $table, string $name): void
    {
        if ($this->hasCheck($table, $name)) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
        }
    }

    private function hasCheck(string $table, string $name): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $name)
            ->where('constraint_type', 'CHECK')
            ->exists();
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
