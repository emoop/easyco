<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * mail-design.md §6 (stage M1): one row per mail the shop decided to send, inserted FIRST and
     * guarded by a UNIQUE idempotency key, so a double event, a replayed checkout and a retried job
     * can never produce two mails. The body is NOT stored — only facts; the order is the record.
     *
     * `idempotency_key` is built from the EVENT, never a timestamp (`order.confirmation:{orderId}`);
     * a duplicate insert is detected by SQLSTATE 23000 + driver code (CLAUDE.md rule 3), never by message.
     *
     * Names are explicit and short (CLAUDE.md rule 5). CHECKs (MySQL/MariaDB only, the supportsCheck()
     * pattern of the order_events migrations): a closed status and category, and `sent_at` present
     * exactly when the status is `sent`.
     *
     * Idempotent on purpose (CLAUDE.md rule 6): a failed half-run can be re-run.
     */
    public function up(): void
    {
        if (! Schema::hasTable('mail_log')) {
            Schema::create('mail_log', function (Blueprint $table) {
                $table->id();
                $table->string('template_key', 64);
                $table->string('idempotency_key', 120);
                $table->string('category', 16);
                $table->string('to_email', 254);
                $table->char('locale', 2);
                $table->string('subject', 180)->nullable();
                $table->string('status', 16);
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->string('last_error', 255)->nullable();
                $table->string('related_type', 32)->nullable();
                $table->string('related_id', 64)->nullable();
                $table->timestamp('queued_at');
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->unique('idempotency_key', 'ml_idempotency_unique');
                $table->index(['status', 'queued_at'], 'ml_status_queued_idx');
                $table->index(['related_type', 'related_id'], 'ml_related_idx');
                $table->index('to_email', 'ml_to_email_idx');
            });
        }

        if ($this->supportsCheck()) {
            DB::statement("ALTER TABLE mail_log ADD CONSTRAINT ml_status_check CHECK (status IN ('queued','sending','sent','failed','skipped'))");
            DB::statement("ALTER TABLE mail_log ADD CONSTRAINT ml_category_check CHECK (category IN ('transactional','marketing'))");
            DB::statement("ALTER TABLE mail_log ADD CONSTRAINT ml_sent_at_check CHECK ((status = 'sent') = (sent_at IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_log');
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            && ! $this->checkExists('ml_status_check');
    }

    /** A half-run migration may already hold the constraints (CLAUDE.md rule 6). */
    private function checkExists(string $name): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'mail_log')
            ->where('constraint_name', $name)
            ->exists();
    }
};
