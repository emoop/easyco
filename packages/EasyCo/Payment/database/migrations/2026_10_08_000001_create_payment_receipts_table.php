<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Refunds R4a-1 (shipping-domain-design.md §7.2.20 §2): the facts of every bank transfer a
     * merchant saw arrive — APPEND-ONLY, one row per observed transfer. No updated_at and no
     * delete path: a correction is a NEW row that names the old one in supersedes_receipt_id; a
     * receipt is EFFECTIVE unless a later row names it. The effective sum of a payment's receipts
     * is what a mismatch is derived from (R4a-2); the payment's own confirmed_at stays write-once.
     *
     *  - payment_id is a REAL foreign key (restrict): unlike payment_refunds.payment_id there is no
     *    "is it captured" rule to keep out of the database here.
     *  - received_on is a DATE: a calendar day in the STORE timezone, never an instant.
     *  - bank_reference VARCHAR(64) NOT NULL and not whitespace-only (tabs and newlines too, which TRIM would let through): what the merchant reconciles against the
     *    bank statement (a SEPA end-to-end id is at most 35 characters; 64 leaves headroom, and the
     *    form's maxLength is 64 too).
     *  - supersedes_receipt_id: nullable self-FK, UNIQUE — a receipt is superseded at most once.
     *  - recorded_by has the shape of payment_refunds.refunded_by; recorded_at is a UTC instant.
     *
     * CHECKs (MySQL/MariaDB only, the supportsCheck() pattern). Names explicit and short
     * (CLAUDE.md rule 5): prc_ prefix.
     */
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('payment_id');
            $table->bigInteger('amount_minor');
            $table->char('amount_currency', 3);
            $table->date('received_on');
            $table->string('bank_reference', 64);

            $table->unsignedBigInteger('supersedes_receipt_id')->nullable();
            $table->string('recorded_by')->nullable();
            $table->timestamp('recorded_at')->useCurrent();

            // The indexes first, so the foreign keys below use them instead of MySQL inventing its own.
            $table->index('payment_id', 'prc_payment_idx');
            $table->unique('supersedes_receipt_id', 'prc_supersedes_unique');

            $table->foreign('payment_id', 'prc_payment_fk')->references('id')->on('payments')->restrictOnDelete();
            $table->foreign('supersedes_receipt_id', 'prc_supersedes_fk')->references('id')->on('payment_receipts')->restrictOnDelete();
        });

        if ($this->supportsCheck()) {
            DB::statement('ALTER TABLE payment_receipts ADD CONSTRAINT prc_amount_check CHECK (amount_minor > 0)');
            DB::statement("ALTER TABLE payment_receipts ADD CONSTRAINT prc_reference_check CHECK (bank_reference REGEXP '[^[:space:]]')");
        }
    }

    /** Refuses while any receipt exists: the table is the record of money seen arriving. */
    public function down(): void
    {
        if (Schema::hasTable('payment_receipts') && DB::table('payment_receipts')->exists()) {
            throw new RuntimeException('payment_receipts holds receipts; rolling back would destroy the record of money received. Nothing was changed.');
        }

        Schema::dropIfExists('payment_receipts');
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
