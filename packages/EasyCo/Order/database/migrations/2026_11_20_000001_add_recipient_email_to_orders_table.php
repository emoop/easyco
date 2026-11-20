<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ACC-3c: `recipient_email` VARCHAR(254) NULL on `orders` ONLY: the optional address that receives the DELIVERY mails
     * (shipped, waybill/tracking) in addition to the buyer. NULL = none. It is delivery data of one order, never an address-book
     * field, and it is not part of the placement snapshot (that records what the confirmation told the buyer).
     *
     * CLAUDE.md rule 2 (MySQL/MariaDB only, explicit short name, rule 5): `ord_recipient_email_check` (25 characters) - never blank. Lower-casing and
     * "not the buyer's own address" are NOT checked here: the column is case-insensitive and the buyer's email is not edited by anything, so Order::create is the one place that normalises.
     *
     * Idempotent (rule 6): a column or CHECK that is already there is left alone, in up() and in down(). No index: nothing filters by it.
     */
    private const CHECK = 'ord_recipient_email_check';

    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'recipient_email')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('recipient_email', 254)->nullable();
            });
        }

        if ($this->supportsCheck() && ! $this->hasCheck()) {
            DB::statement('ALTER TABLE orders ADD CONSTRAINT '.self::CHECK." CHECK (recipient_email IS NULL OR TRIM(recipient_email) <> '')");
        }
    }

    public function down(): void
    {
        if ($this->supportsCheck() && $this->hasCheck()) {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT '.self::CHECK);
        }

        if (Schema::hasColumn('orders', 'recipient_email')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('recipient_email');
            });
        }
    }

    private function hasCheck(): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'orders')
            ->where('constraint_name', self::CHECK)
            ->where('constraint_type', 'CHECK')
            ->exists();
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
