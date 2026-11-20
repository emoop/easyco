<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ACC-4a (acc-4-gdpr-design.md §3): the suppression list. One row per (kind, keyed hash), UNIQUE: the database, not the code, makes a second row for the
     * same person impossible (two concurrent erasures insert-or-ignore). `request_id` restricts: the log row that created a suppression is never deleted.
     * A suppression is lifted by `lifted_at` + `lifted_reason`, never by deleting the row. CHECK `psup_kind_check` (explicit short name, rule 5).
     * Idempotent (rule 6).
     */
    public function up(): void
    {
        if (! Schema::hasTable('privacy_suppressions')) {
            Schema::create('privacy_suppressions', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 5);
                $table->char('hash', 64);
                $table->foreignId('request_id')->constrained('privacy_requests', indexName: 'psup_request_id_foreign')->restrictOnDelete();
                $table->timestamp('lifted_at')->nullable();
                $table->string('lifted_reason', 500)->nullable();
                $table->timestamps();

                $table->unique(['kind', 'hash'], 'psup_kind_hash_unique');
            });
        }

        if ($this->supportsCheck() && ! $this->hasCheck()) {
            DB::statement("ALTER TABLE privacy_suppressions ADD CONSTRAINT psup_kind_check CHECK (kind IN ('email', 'phone'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_suppressions');
    }

    private function hasCheck(): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'privacy_suppressions')
            ->where('constraint_name', 'psup_kind_check')
            ->where('constraint_type', 'CHECK')
            ->exists();
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
