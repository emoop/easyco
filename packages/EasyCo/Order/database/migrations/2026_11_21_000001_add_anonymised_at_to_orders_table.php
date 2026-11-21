<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ACC-4b (acc-4-gdpr-design.md D8): `anonymised_at` TIMESTAMP NULL on `orders`: when the personal data of the order was anonymised after the
     * retention period; NULL = never. One-way.
     *
     * Index `ord_anon_placed_index` (anonymised_at, placed_at): the daily command asks `anonymised_at IS NULL AND placed_at < cutoff`.
     *
     * CLAUDE.md rule 2 (a real constraint, MySQL/MariaDB only), explicit short name (rule 5):
     *  - `ord_anon_status_check`: an anonymised order is a FINISHED one (delivered, cancelled, refunded).
     * NOT a CHECK on the four identity keys being NULL for an anonymised order: a constraint over those columns would stop the earlier migration that adds
     * them from being rolled back on its own (OrderIdentityKeysStorageTest round-trips it). EloquentOrderRepository::save() and the tests hold that rule.
     * Every existing row satisfies it (anonymised_at is NULL everywhere). Idempotent (rule 6): a column, index or CHECK already there is left alone,
     * in up() and in down().
     */
    private const CHECKS = [
        'ord_anon_status_check' => "anonymised_at IS NULL OR status IN ('delivered', 'cancelled', 'refunded')",
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'anonymised_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('anonymised_at')->nullable();
            });
        }

        if (! Schema::hasIndex('orders', 'ord_anon_placed_index')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->index(['anonymised_at', 'placed_at'], 'ord_anon_placed_index');
            });
        }

        if ($this->supportsCheck()) {
            foreach (self::CHECKS as $name => $expression) {
                if (! $this->hasCheck($name)) {
                    DB::statement("ALTER TABLE orders ADD CONSTRAINT {$name} CHECK ({$expression})");
                }
            }
        }
    }

    public function down(): void
    {
        if ($this->supportsCheck()) {
            foreach (array_keys(self::CHECKS) as $name) {
                if ($this->hasCheck($name)) {
                    DB::statement("ALTER TABLE orders DROP CONSTRAINT {$name}");
                }
            }
        }

        if (Schema::hasIndex('orders', 'ord_anon_placed_index')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex('ord_anon_placed_index');
            });
        }

        if (Schema::hasColumn('orders', 'anonymised_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('anonymised_at');
            });
        }
    }

    private function hasCheck(string $name): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'orders')
            ->where('constraint_name', $name)
            ->where('constraint_type', 'CHECK')
            ->exists();
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
