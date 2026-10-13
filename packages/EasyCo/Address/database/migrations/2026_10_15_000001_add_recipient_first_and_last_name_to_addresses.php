<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkout form v2, stage C1d-A (checkout-form-v2-design.md section 2.2 and 3): the recipient's name is TWO fields, and a pick-up
     * point is identified OR described.
     *
     *  - `recipient_first_name` VARCHAR(100) NOT NULL and `recipient_last_name` VARCHAR(100) NOT NULL replace `recipient_name`.
     *  - BACKFILL of the rows that exist: the old name is trimmed and split at its LAST run of whitespace ("Иван Петров Иванов" becomes
     *    "Иван Петров" / "Иванов"); ONE word goes whole into the first name and the last name is the empty string (legal only for a row
     *    that existed before the split: the entity's reconstituteFromStorage() accepts it, create() and update() refuse it). A part
     *    longer than 100 characters is cut to 100 (the old column was 255; the data is test data, D1).
     *  - `recipient_name` is then DROPPED. down() re-creates it as trim(first + ' ' + last).
     *
     * CLAUDE.md rule 2, MySQL/MariaDB only, explicit short names (rule 5):
     *  - `addr_first_name_check` (21 characters): the first name is not blank (the last may be empty: a historical row);
     *  - `addr_pickup_ident_check` (23): a pick-up point has a reference OR a description (`pickup_point_address`), at least one.
     * Every existing row satisfies both (every old pick-up row has a reference; the first name is never empty after the split).
     *
     * Idempotent (rule 6): a column, a CHECK or a backfill that is already done is left alone, in up() and in down(). MySQL DDL is not
     * transactional: after a failed run look at what exists before re-running.
     */
    public const TABLE = 'addresses';

    public const CHECKS = [
        'addr_first_name_check' => "TRIM(recipient_first_name) <> ''",
        'addr_pickup_ident_check' => "delivery_type <> 'pickup_point' OR (pickup_point_reference IS NOT NULL AND TRIM(pickup_point_reference) <> '') OR (pickup_point_address IS NOT NULL AND TRIM(pickup_point_address) <> '')",
    ];

    public function up(): void
    {
        $table = self::TABLE;

        foreach (['recipient_first_name', 'recipient_last_name'] as $column) {
            if (! Schema::hasColumn($table, $column)) {
                // A default only while the rows are filled; it is dropped below so a NEW row can never skip the name.
                Schema::table($table, function (Blueprint $blueprint) use ($column) {
                    $blueprint->string($column, 100)->default('');
                });
            }
        }

        if (Schema::hasColumn($table, 'recipient_name')) {
            DB::table($table)->select(['id', 'recipient_name'])->orderBy('id')->chunkById(500, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    [$first, $last] = self::split((string) $row->recipient_name);
                    DB::table($table)->where('id', $row->id)->update(['recipient_first_name' => $first, 'recipient_last_name' => $last]);
                }
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('recipient_name');
            });
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (['recipient_first_name', 'recipient_last_name'] as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} DROP DEFAULT");
            }

            foreach (self::CHECKS as $name => $expression) {
                if (! $this->hasCheck($table, $name)) {
                    DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
                }
            }
        }
    }

    /** The checks first, then the old column back (filled from the two parts), then the new columns go. */
    public function down(): void
    {
        $table = self::TABLE;

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (array_keys(self::CHECKS) as $name) {
                if ($this->hasCheck($table, $name)) {
                    DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
                }
            }
        }

        if (! Schema::hasColumn($table, 'recipient_name')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('recipient_name')->default('');
            });
        }

        if (Schema::hasColumn($table, 'recipient_first_name')) {
            DB::table($table)->select(['id', 'recipient_first_name', 'recipient_last_name'])->orderBy('id')->chunkById(500, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update(['recipient_name' => trim($row->recipient_first_name.' '.$row->recipient_last_name)]);
                }
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn(['recipient_first_name', 'recipient_last_name']);
            });
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN recipient_name DROP DEFAULT");
        }
    }

    /**
     * The old single name as [first, last]: trimmed, split at the LAST run of whitespace; one word is all first name.
     *
     * @return array{0: string, 1: string}
     */
    public static function split(string $name): array
    {
        $name = trim($name);

        if (preg_match('/^(.*\S)\s+(\S+)$/su', $name, $parts) === 1) {
            return [mb_substr($parts[1], 0, 100), mb_substr($parts[2], 0, 100)];
        }

        return [mb_substr($name, 0, 100), ''];
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
};
