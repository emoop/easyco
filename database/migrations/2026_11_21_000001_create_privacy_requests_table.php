<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ACC-4a (acc-4-gdpr-design.md §3): `privacy_requests`, one row per request; the «дневник на забравените» is `kind = erase`.
     * `account_id` is a snapshot, NOT a foreign key: the account is gone when the row is written. The clear `email` / `phone` live until `clear_until`
     * (30 days after an erase); the hashes are what stays. Rows are never deleted by the application.
     *
     * CLAUDE.md rule 2 (real constraints, MySQL/MariaDB only), explicit short names (rule 5): `prq_kind_check`, `prq_source_check`, `prq_status_check`,
     * `prq_reason_check` (an admin erase carries a reason). Idempotent (rule 6): a table or CHECK already there is left alone, in up() and in down().
     */
    private const CHECKS = [
        'prq_kind_check' => "kind IN ('export', 'erase')",
        'prq_source_check' => "source IN ('storefront', 'account', 'admin')",
        'prq_status_check' => "status IN ('awaiting_confirmation', 'confirmed', 'processing', 'done', 'expired', 'failed')",
        'prq_reason_check' => "(source <> 'admin' OR kind <> 'erase') OR (reason IS NOT NULL AND TRIM(reason) <> '')",
    ];

    public function up(): void
    {
        if (! Schema::hasTable('privacy_requests')) {
            Schema::create('privacy_requests', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 8);
                $table->string('source', 12);
                $table->string('status', 24);
                $table->string('email', 254)->nullable();
                $table->char('email_hash', 64);
                $table->string('phone', 32)->nullable();
                $table->char('phone_hash', 64)->nullable();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->string('reason', 500)->nullable();
                $table->unsignedBigInteger('staff_id')->nullable();
                $table->string('staff_name', 255)->nullable();
                $table->json('actions')->nullable();
                $table->json('manual_steps')->nullable();
                $table->timestamp('clear_until')->nullable();
                $table->string('export_path', 255)->nullable();
                $table->timestamp('export_expires_at')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['kind', 'email_hash'], 'prq_kind_email_hash_idx');
                $table->index('status', 'prq_status_idx');
                $table->index('clear_until', 'prq_clear_until_idx');
            });
        }

        if ($this->supportsCheck()) {
            foreach (self::CHECKS as $name => $expression) {
                if (! $this->hasCheck($name)) {
                    DB::statement("ALTER TABLE privacy_requests ADD CONSTRAINT {$name} CHECK ({$expression})");
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_requests');
    }

    private function hasCheck(string $name): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'privacy_requests')
            ->where('constraint_name', $name)
            ->where('constraint_type', 'CHECK')
            ->exists();
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
