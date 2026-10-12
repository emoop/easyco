<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shipping stage 5g-1 (shipping-domain-design.md 9.3.2 A): the FREE SET of a method — the kinds of destination (address, office,
     * locker) its free-shipping threshold applies to — as three booleans, so each fact is one CHECK-able column.
     *
     *  - `free_for_address`, `free_for_office`, `free_for_locker`: TINYINT(1) NOT NULL, DB DEFAULTS 0 / 1 / 1. The default is the
     *    owner's: free delivery usually only to an office or a locker. Adding the columns therefore gives EVERY EXISTING METHOD
     *    office + locker (the owner's migration rule), with no data pass.
     *  - THE BACKFILL REFINEMENT (decision Q1), done only in the run that adds the columns (a re-run never overwrites what a
     *    merchant has since chosen): a method whose served kinds do not include office or locker would otherwise carry a threshold
     *    that can never apply, so its set is made to match what it serves: delivery scope `address` or label `address` -> address
     *    only; label `office` -> office only; label `locker` -> locker only. Every other method (scope any or pickup, no label or
     *    `other`) keeps the default office + locker.
     *  - Two CHECKs (MySQL/MariaDB only, the supportsCheck() pattern; CLAUDE.md rules 2 and 5, names explicit and short):
     *    `ship_methods_free_flags_check` (29 characters): each flag is 0 or 1;
     *    `ship_methods_free_served_check` (30 characters): a method with a threshold must have at least one free kind it actually
     *    serves — the same label x scope matrix `ship_methods_label_scope_check` encodes (the entity refuses the same thing first
     *    with a readable message; the CHECK is the net).
     *  - IDEMPOTENT (rule 6): a column or CHECK that is already there is left alone, in up() and in down(); MySQL DDL is not
     *    transactional, so after a failed run look at what exists before re-running.
     */
    public const FLAGS_CHECK = 'ship_methods_free_flags_check';

    public const SERVED_CHECK = 'ship_methods_free_served_check';

    /** column => DB default */
    private const COLUMNS = ['free_for_address' => 0, 'free_for_office' => 1, 'free_for_locker' => 1];

    public function up(): void
    {
        $added = false;

        foreach (self::COLUMNS as $column => $default) {
            if (! Schema::hasColumn('shipping_methods', $column)) {
                Schema::table('shipping_methods', function (Blueprint $table) use ($column, $default) {
                    $table->boolean($column)->default($default !== 0);
                });
                $added = true;
            }
        }

        if ($added) {
            $this->backfill();
        }

        if ($this->supportsCheck()) {
            if (! $this->hasCheck(self::FLAGS_CHECK)) {
                DB::statement('ALTER TABLE shipping_methods ADD CONSTRAINT '.self::FLAGS_CHECK
                    .' CHECK (free_for_address IN (0, 1) AND free_for_office IN (0, 1) AND free_for_locker IN (0, 1))');
            }

            if (! $this->hasCheck(self::SERVED_CHECK)) {
                DB::statement('ALTER TABLE shipping_methods ADD CONSTRAINT '.self::SERVED_CHECK.' CHECK ('
                    .'free_above_minor IS NULL'
                    ." OR (free_for_address = 1 AND destination_scope IN ('address', 'any') AND (delivery_type IS NULL OR delivery_type IN ('address', 'other')))"
                    ." OR (free_for_office = 1 AND destination_scope IN ('pickup', 'any') AND (delivery_type IS NULL OR delivery_type IN ('office', 'other')))"
                    ." OR (free_for_locker = 1 AND destination_scope IN ('pickup', 'any') AND (delivery_type IS NULL OR delivery_type IN ('locker', 'other')))"
                    .')');
            }
        }
    }

    /** The checks first, then the columns. */
    public function down(): void
    {
        if ($this->supportsCheck()) {
            foreach ([self::SERVED_CHECK, self::FLAGS_CHECK] as $check) {
                if ($this->hasCheck($check)) {
                    DB::statement('ALTER TABLE shipping_methods DROP CONSTRAINT '.$check);
                }
            }
        }

        $present = array_values(array_filter(array_keys(self::COLUMNS), fn (string $column): bool => Schema::hasColumn('shipping_methods', $column)));

        if ($present !== []) {
            Schema::table('shipping_methods', function (Blueprint $table) use ($present) {
                $table->dropColumn($present);
            });
        }
    }

    /** Q1: make the new default match what each existing method serves, so no threshold is left that can never apply. */
    private function backfill(): void
    {
        DB::table('shipping_methods')
            ->where(fn ($q) => $q->where('destination_scope', 'address')->orWhere('delivery_type', 'address'))
            ->update(['free_for_address' => 1, 'free_for_office' => 0, 'free_for_locker' => 0]);

        DB::table('shipping_methods')->where('delivery_type', 'office')->update(['free_for_address' => 0, 'free_for_office' => 1, 'free_for_locker' => 0]);
        DB::table('shipping_methods')->where('delivery_type', 'locker')->update(['free_for_address' => 0, 'free_for_office' => 0, 'free_for_locker' => 1]);
    }

    private function hasCheck(string $name): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'shipping_methods')
            ->where('constraint_name', $name)
            ->where('constraint_type', 'CHECK')
            ->exists();
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
