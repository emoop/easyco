<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ACC-4d (acc-4-gdpr-design.md §6, D2): a CONFIRMATION request is a row `kind = erase`, `source = admin`, `status = awaiting_confirmation` WITHOUT a reason: the merchant gives the
     * reason when they press «Забрави» (ACC-4e), later. ACC-4a's `prq_reason_check` demanded a reason of EVERY admin erase row, so such a row could not be written.
     *
     * The check now exempts the statuses of the confirmation's own life (`awaiting_confirmation`, `confirmed`, `expired`). A row of the FORGET LOG (`processing`, `done`, `failed`) still needs
     * its reason, exactly as before. Short name (rule 5), CHECK on MySQL/MariaDB only (the same as ACC-4a), idempotent (rule 6): the old CHECK is dropped only when present, the new one added
     * only when absent. down() restores the ACC-4a form and fails loudly if a reason-less confirmation row exists (that row must not be deleted by a migration: history).
     */
    private const NAME = 'prq_reason_check';

    private const RELAXED = "(source <> 'admin' OR kind <> 'erase' OR status IN ('awaiting_confirmation', 'confirmed', 'expired')) OR (reason IS NOT NULL AND TRIM(reason) <> '')";

    private const ORIGINAL = "(source <> 'admin' OR kind <> 'erase') OR (reason IS NOT NULL AND TRIM(reason) <> '')";

    public function up(): void
    {
        $this->replace(self::RELAXED);
    }

    public function down(): void
    {
        $this->replace(self::ORIGINAL);
    }

    private function replace(string $expression): void
    {
        if (! Schema::hasTable('privacy_requests') || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $this->hasCheck() && DB::statement('ALTER TABLE privacy_requests DROP CONSTRAINT '.self::NAME);
        DB::statement('ALTER TABLE privacy_requests ADD CONSTRAINT '.self::NAME.' CHECK ('.$expression.')');
    }

    private function hasCheck(): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'privacy_requests')
            ->where('constraint_name', self::NAME)
            ->where('constraint_type', 'CHECK')
            ->exists();
    }
};
