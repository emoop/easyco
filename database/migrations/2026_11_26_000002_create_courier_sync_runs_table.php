<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per courier points sync (courier-integration-design.md §3.1): the admin's sync report and the source of the
 * «needs attention» row for a stale courier. NO PERSONAL DATA and no credentials: a carrier code, counts and a closed error code.
 *
 * `outcome` is one of ok | partial | failed | refused_shrink (CHECK `csr_outcome_check`). `trigger` is who started it:
 * schedule | manual | cli. `skipped` is the number of malformed items the run left out (an addition to the design's counts).
 * `unmapped` is the number of points with no settlement: always 0 until COUR-1b creates settlements.
 *
 * Index `csr_carrier_started_idx (carrier_code, started_at)` serves "the latest runs of this courier". Names are all under 64 (rule 5).
 * Idempotent (rule 6).
 */
return new class extends Migration
{
    private const TABLE = 'courier_sync_runs';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) {
                $table->id();
                $table->string('carrier_code', 32);
                $table->string('trigger', 10);
                $table->timestamp('started_at', 6);
                $table->timestamp('finished_at', 6)->nullable();
                $table->string('outcome', 16);
                $table->unsignedInteger('seen')->default(0);
                $table->unsignedInteger('new_count')->default(0);
                $table->unsignedInteger('changed')->default(0);
                $table->unsignedInteger('deactivated')->default(0);
                $table->unsignedInteger('unmapped')->default(0);
                $table->unsignedInteger('skipped')->default(0);
                $table->string('error_code', 64)->nullable();

                $table->index(['carrier_code', 'started_at'], 'csr_carrier_started_idx');
            });
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && ! $this->hasCheck()) {
            DB::statement('ALTER TABLE '.self::TABLE." ADD CONSTRAINT csr_outcome_check CHECK (outcome COLLATE utf8mb4_bin IN ('ok', 'partial', 'failed', 'refused_shrink'))");
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
            ->where('CONSTRAINT_NAME', 'csr_outcome_check')
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->exists();
    }
};
