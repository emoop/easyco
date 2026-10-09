<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shipping stage 6a (shipping-domain-design.md section 9.2): a method's destination SCOPE — address | pickup | any — replaces
     * the boolean `requires_pickup_point`.
     *
     *  - THE GATE FIRST, like 2026_10_02_000011: before anything is touched, up() counts the methods that would break the label x
     *    scope rule once the old boolean is read as a scope (false -> address, true -> pickup): a label `address` on a pickup
     *    method, or a label `office` / `locker` on an address method. If there are any it throws, naming their ids, and changes
     *    NOTHING (MySQL's DDL is not transactional, CLAUDE.md rule 6; correct those methods by hand and re-run).
     *  - Then `destination_scope` VARCHAR(8) is added NULL, backfilled (1 -> 'pickup', 0 -> 'address': every existing method keeps
     *    its present meaning; nothing becomes 'any' by itself), made NOT NULL, guarded by two CHECKs, and the old column is DROPPED.
     *  - Explicit short names (rule 5): `ship_methods_scope_check` is 24 characters, `ship_methods_label_scope_check` is 30.
     *    `ship_methods_label_scope_check` is the label x scope matrix: label address needs scope address, label office or locker
     *    needs scope pickup, a NULL or `other` label accepts any scope. CHECKs on MySQL/MariaDB only, like ship_methods_delivery_type_check.
     *  - IDEMPOTENT (rule 6): a column or CHECK that is already there is left alone, in up() and in down().
     *
     * down() IS LOSSY FOR 'any': it re-creates `requires_pickup_point` from the scope (pickup -> 1; address and ANY -> 0), so a
     * method that served both kinds comes back as an address-only method. That is the only information the old column could not hold.
     */
    private const SCOPE_CHECK = 'ship_methods_scope_check';

    private const LABEL_CHECK = 'ship_methods_label_scope_check';

    public function up(): void
    {
        $offenders = $this->offenders();

        if ($offenders !== []) {
            throw new RuntimeException(sprintf(
                'shipping_methods break the label x scope rule (label address needs scope address; office and locker need scope pickup) in %d %s (shipping_methods.id: %s). '
                .'Nothing was changed; correct those methods (change the delivery type or the pickup toggle) and re-run the migration.',
                count($offenders),
                count($offenders) === 1 ? 'row' : 'rows',
                implode(', ', array_slice($offenders, 0, 20)).(count($offenders) > 20 ? ', …' : ''),
            ));
        }

        if (! Schema::hasColumn('shipping_methods', 'destination_scope')) {
            Schema::table('shipping_methods', function (Blueprint $table) {
                $table->string('destination_scope', 8)->nullable();
            });
        }

        if (Schema::hasColumn('shipping_methods', 'requires_pickup_point')) {
            DB::table('shipping_methods')->whereNull('destination_scope')->where('requires_pickup_point', true)->update(['destination_scope' => 'pickup']);
            DB::table('shipping_methods')->whereNull('destination_scope')->update(['destination_scope' => 'address']);
        }

        $column = collect(Schema::getColumns('shipping_methods'))->firstWhere('name', 'destination_scope');

        if ($column !== null && $column['nullable']) {
            Schema::table('shipping_methods', function (Blueprint $table) {
                $table->string('destination_scope', 8)->nullable(false)->change();
            });
        }

        if ($this->supportsCheck()) {
            if (! $this->hasCheck(self::SCOPE_CHECK)) {
                DB::statement('ALTER TABLE shipping_methods ADD CONSTRAINT '.self::SCOPE_CHECK." CHECK (destination_scope IN ('address', 'pickup', 'any'))");
            }

            if (! $this->hasCheck(self::LABEL_CHECK)) {
                DB::statement(
                    'ALTER TABLE shipping_methods ADD CONSTRAINT '.self::LABEL_CHECK.' CHECK ('
                    ."delivery_type IS NULL OR delivery_type = 'other'"
                    ." OR (delivery_type = 'address' AND destination_scope = 'address')"
                    ." OR (delivery_type IN ('office', 'locker') AND destination_scope = 'pickup'))"
                );
            }
        }

        if (Schema::hasColumn('shipping_methods', 'requires_pickup_point')) {
            Schema::table('shipping_methods', function (Blueprint $table) {
                $table->dropColumn('requires_pickup_point');
            });
        }
    }

    /** Lossy for 'any' (any -> 0): see the class docblock. */
    public function down(): void
    {
        if (! Schema::hasColumn('shipping_methods', 'requires_pickup_point')) {
            Schema::table('shipping_methods', function (Blueprint $table) {
                $table->boolean('requires_pickup_point')->default(false);
            });
        }

        if (Schema::hasColumn('shipping_methods', 'destination_scope')) {
            DB::table('shipping_methods')->update(['requires_pickup_point' => false]);
            DB::table('shipping_methods')->where('destination_scope', 'pickup')->update(['requires_pickup_point' => true]);
        }

        if ($this->supportsCheck()) {
            foreach ([self::LABEL_CHECK, self::SCOPE_CHECK] as $check) {
                if ($this->hasCheck($check)) {
                    DB::statement('ALTER TABLE shipping_methods DROP CONSTRAINT '.$check);
                }
            }
        }

        if (Schema::hasColumn('shipping_methods', 'destination_scope')) {
            Schema::table('shipping_methods', function (Blueprint $table) {
                $table->dropColumn('destination_scope');
            });
        }
    }

    /**
     * The ids that break the label x scope rule, judged on whichever column still holds the scope: the old boolean before the
     * migration ran (or partly ran), the new column afterwards.
     *
     * @return list<string>
     */
    private function offenders(): array
    {
        $query = DB::table('shipping_methods')->orderBy('id');

        if (Schema::hasColumn('shipping_methods', 'requires_pickup_point')) {
            $query->where(function ($q) {
                $q->where(fn ($a) => $a->where('delivery_type', 'address')->where('requires_pickup_point', true))
                    ->orWhere(fn ($b) => $b->whereIn('delivery_type', ['office', 'locker'])->where('requires_pickup_point', false));
            });
        } elseif (Schema::hasColumn('shipping_methods', 'destination_scope')) {
            $query->where(function ($q) {
                $q->where(fn ($a) => $a->where('delivery_type', 'address')->where('destination_scope', '<>', 'address'))
                    ->orWhere(fn ($b) => $b->whereIn('delivery_type', ['office', 'locker'])->where('destination_scope', '<>', 'pickup'));
            });
        } else {
            return [];
        }

        return $query->pluck('id')->map(fn ($id) => (string) $id)->all();
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
