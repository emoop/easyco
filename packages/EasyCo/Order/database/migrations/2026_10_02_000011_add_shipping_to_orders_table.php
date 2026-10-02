<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * shipping-domain-design.md §7 / §7.1 (shipping stage 2): an order now
     * carries an order-level shipping amount, and `total = subtotal - discount
     * + shipping`.
     *
     * THE GATE FIRST, LIKE guard_no_fulfilled_orders: before anything is
     * altered, up() counts the rows that do not already satisfy the OLD formula
     * (`total_minor = subtotal_minor - discount_minor`). Those would violate
     * the new one the moment shipping_minor defaults to 0, and a CHECK that
     * cannot be added is better found here, loudly and with the ids, than as a
     * half-applied migration (MySQL's DDL is non-transactional — CLAUDE.md
     * rule 6). Nothing is written when it throws.
     *
     * shipping_minor is a signed bigInteger like subtotal/discount/total_minor
     * (no separate currency column: orders share one `currency`). The two
     * method columns are plain snapshots (shipping-domain-design.md §7: "the
     * method name is snapshotted, not referenced"), nullable because every
     * order placed before shipping existed — and every order with no shipping
     * method — has none.
     *
     * THE CHECK (CLAUDE.md rule 2: an invariant app code enforces is also a
     * real database constraint wherever physically possible): the order's
     * total is exactly its parts, enforced by the database too. Added only on
     * MySQL/MariaDB (MySQL enforces CHECK from 8.0.16, MariaDB from 10.2.1);
     * other drivers (SQLite in some local setups) keep only the entity's own
     * rule. The name is explicit and short (rule 5).
     */
    private const CHECK = 'orders_total_formula_check';

    public function up(): void
    {
        $offenders = DB::table('orders')
            ->whereRaw('total_minor <> subtotal_minor - discount_minor')
            ->orderBy('id')
            ->pluck('id');

        if ($offenders->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'orders.total_minor <> subtotal_minor - discount_minor in %d %s (orders.id: %s). '
                .'Adding shipping_minor (default 0) and the total formula CHECK would be refused for them. '
                .'Nothing was changed; correct those orders by hand and re-run the migration.',
                $offenders->count(),
                $offenders->count() === 1 ? 'row' : 'rows',
                $offenders->take(20)->implode(', ').($offenders->count() > 20 ? ', …' : ''),
            ));
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->bigInteger('shipping_minor')->default(0)->after('discount_minor');
            $table->string('shipping_method_name', 255)->nullable()->after('shipping_minor');
            $table->string('shipping_method_code', 64)->nullable()->after('shipping_method_name');
        });

        if ($this->supportsCheck()) {
            DB::statement(
                'ALTER TABLE orders ADD CONSTRAINT '.self::CHECK
                .' CHECK (total_minor = subtotal_minor - discount_minor + shipping_minor)'
            );
        }
    }

    /**
     * The check first, then the columns. DROP CONSTRAINT is accepted by MySQL
     * 8.0.19+ and MariaDB 10.2.1+ for a CHECK.
     */
    public function down(): void
    {
        if ($this->supportsCheck()) {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT '.self::CHECK);
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_method_code', 'shipping_method_name', 'shipping_minor']);
        });
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
