<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The courier points table (courier-integration-design.md §3.1): one row per office or locker a courier lists, kept in OUR
 * database so the checkout never asks a courier live.
 *
 * NEVER HARD-DELETED (CLAUDE.md rule 4): a point that disappears from the courier's list is deactivated, because orders and
 * shipments name it by reference and the admin must still read what it was.
 *
 * IDENTIFIER LENGTHS (rule 5, all under 64): unique `spp_carrier_ref_uq`, index `spp_lookup_idx`, CHECK `spp_kind_check`.
 * The DB constraints (rule 2): the unique key makes a second row for the same courier point impossible, the CHECK makes
 * `kind` one of the two values whatever writes it (compared in a BINARY collation: the column's own collation is case-insensitive,
 * so a plain IN would let 'LOCKER' through).
 *
 * `settlement_id` is NULLABLE AND HAS NO FOREIGN KEY YET: `shipping_settlements` is stage COUR-1b. That migration creates the
 * settlements table and then adds the foreign key to this column as a plain restricting FK named `spp_settlement_fk`
 * (settlements are never deleted either, so no ON DELETE action); every row written
 * until then has NULL, which is also the design's "not mapped" value, so the FK can be added without touching a row.
 *
 * Timestamps are microsecond precision on purpose: `synced_at` is how a sync run tells "seen in this run" from "not seen"
 * (rows older than the run's start are deactivated), and two runs can start inside one second.
 *
 * Idempotent (rule 6): MySQL DDL is not transactional; a table or CHECK that is already there is left alone.
 */
return new class extends Migration
{
    private const TABLE = 'shipping_pickup_points';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) {
                $table->id();
                $table->string('carrier_code', 32);
                $table->string('external_ref', 64);
                $table->string('external_id', 64)->nullable();
                $table->string('kind', 10);
                $table->string('courier_type', 16)->nullable();
                $table->string('name', 255);
                $table->unsignedBigInteger('settlement_id')->nullable();
                $table->string('settlement_name', 120);
                $table->string('postcode', 12)->nullable();
                $table->string('address_line', 255);
                $table->decimal('latitude', 9, 6)->nullable();
                $table->decimal('longitude', 9, 6)->nullable();
                $table->json('features')->nullable();
                $table->json('opening_hours')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('first_seen_at', 6)->nullable();
                $table->timestamp('synced_at', 6)->nullable();
                $table->timestamp('deactivated_at', 6)->nullable();

                $table->unique(['carrier_code', 'external_ref'], 'spp_carrier_ref_uq');
                $table->index(['carrier_code', 'kind', 'is_active', 'settlement_id'], 'spp_lookup_idx');
            });
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && ! $this->hasCheck()) {
            DB::statement('ALTER TABLE '.self::TABLE." ADD CONSTRAINT spp_kind_check CHECK (kind COLLATE utf8mb4_bin IN ('office', 'locker'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function hasCheck(): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('CONSTRAINT_NAME', 'spp_kind_check')
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->exists();
    }
};
