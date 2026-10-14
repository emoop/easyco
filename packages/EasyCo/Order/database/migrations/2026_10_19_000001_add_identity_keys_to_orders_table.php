<?php

use EasyCo\Order\IdentityKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stage C2-B (cod-protection-design.md section 2): the four IDENTITY KEYS of an order, derived by the one normaliser
     * (EasyCo\Order\IdentityKeys) from the contact and delivery facts the row already holds:
     *
     *  - `phone_key`  VARCHAR(20)  — the last 9 digits of the phone (NULL for a short or odd number);
     *  - `email_key`  VARCHAR(255) — the email, trimmed and lower-cased;
     *  - `name_key`   VARCHAR(201) — first + last name, lower-cased, whitespace collapsed (a WEAK key: "possible match" only);
     *  - `place_key`  CHAR(40)     — sha1 of the normalised delivery place (street address, or carrier + pick-up point text).
     *
     * ALL NULLABLE and DERIVED: written in the same INSERT as the order by EloquentOrderRepository, recomputed by every save, and rebuilt for
     * the whole table by `php artisan orders:rebuild-identity-keys` when a rule of the normaliser changes. Each has an index
     * (`ord_phone_key_index`, `ord_email_key_index`, `ord_name_key_index`, `ord_place_key_index`: the matcher reads by key, newest first).
     * The placement snapshot table is NOT given the keys: it is the copy of what was agreed, and the keys are derived, current data.
     *
     * THE BACK-FILL: every existing row gets its keys here, in chunks, touching only the four key columns (a row that already has an email
     * key was filled, so a second run changes nothing).
     *
     * CLAUDE.md rule 2 (MySQL/MariaDB only), explicit short names (rule 5): `ord_phone_key_check` (19) — a phone key is exactly nine
     * digits; `ord_place_key_check` (19) — a place key is exactly forty characters. Idempotent (rule 6): a column, index or CHECK that is
     * already there is left alone, in up() and in down().
     */
    public const COLUMNS = ['phone_key' => 20, 'email_key' => 255, 'name_key' => 201, 'place_key' => 40];

    public const INDEXES = [
        'ord_phone_key_index' => 'phone_key',
        'ord_email_key_index' => 'email_key',
        'ord_name_key_index' => 'name_key',
        'ord_place_key_index' => 'place_key',
    ];

    public const CHECKS = [
        'ord_phone_key_check' => "phone_key IS NULL OR phone_key REGEXP '^[0-9]{9}$'",
        'ord_place_key_check' => 'place_key IS NULL OR CHAR_LENGTH(place_key) = 40',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $column => $length) {
            if (! Schema::hasColumn('orders', $column)) {
                Schema::table('orders', function (Blueprint $table) use ($column, $length) {
                    $column === 'place_key' ? $table->char($column, $length)->nullable() : $table->string($column, $length)->nullable();
                });
            }
        }

        foreach (self::INDEXES as $name => $column) {
            if (! $this->hasIndex($name)) {
                Schema::table('orders', fn (Blueprint $table) => $table->index($column, $name));
            }
        }

        $this->backfill();

        if ($this->supportsCheck()) {
            foreach (self::CHECKS as $name => $expression) {
                if (! $this->hasCheck($name)) {
                    DB::statement("ALTER TABLE orders ADD CONSTRAINT {$name} CHECK ({$expression})");
                }
            }
        }
    }

    /** The checks first, then the indexes, then the columns. */
    public function down(): void
    {
        if ($this->supportsCheck()) {
            foreach (array_keys(self::CHECKS) as $name) {
                if ($this->hasCheck($name)) {
                    DB::statement("ALTER TABLE orders DROP CHECK {$name}");
                }
            }
        }

        foreach (array_keys(self::INDEXES) as $name) {
            if ($this->hasIndex($name)) {
                Schema::table('orders', fn (Blueprint $table) => $table->dropIndex($name));
            }
        }

        foreach (array_keys(self::COLUMNS) as $column) {
            if (Schema::hasColumn('orders', $column)) {
                Schema::table('orders', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    /** Fills the keys of every order that has none yet (an email key is present on every filled row: an order's email is never empty). */
    private function backfill(): void
    {
        DB::table('orders')
            ->whereNull('email_key')
            ->orderBy('id')
            ->select(['id', 'email', 'phone', 'recipient_first_name', 'recipient_last_name', 'delivery_type', 'country', 'city', 'address_line_1', 'carrier_code', 'pickup_point_address'])
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('orders')->where('id', $row->id)->update(IdentityKeys::forRow($row));
                }
            });
    }

    private function hasIndex(string $name): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'orders')
            ->where('index_name', $name)
            ->exists();
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
