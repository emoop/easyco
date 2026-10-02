<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Refunds R1b (shipping-domain-design.md §7.2.3, owner decision R1a-4):
     * idempotency of the operations that move an order's money, and the
     * reference the `refunded` / `refund_owed` event gains to its refund record
     * (§7.2.8).
     *
     *  - operation_key: the key the cancel/return dialog generated when it was
     *    opened. It is stored on the FIRST event the operation writes (the
     *    `returned` event of a goods-moving operation), so an operation that
     *    writes no refund at all — a full cancel of a pending payment — is
     *    covered too.
     *  - operation_payload_hash: sha256 of everything the merchant decided. A
     *    repeat with the same key and the SAME hash returns the first result and
     *    writes nothing; the same key with a DIFFERENT hash is refused.
     *  - payment_refund_id: the refund the event records.
     *
     * UNIQUE (order_id, operation_key): per order, so a key cannot collide across
     * orders; MySQL/MariaDB allow many NULLs, so events without a key are
     * unaffected. This is the database backstop; the application replays under
     * the order lock before it ever reaches it.
     *
     * CHECK (MySQL/MariaDB only, same supportsCheck() pattern as
     * orders_total_formula_check): a key and its hash are both present or both
     * absent. Names explicit and short (CLAUDE.md rule 5).
     */
    public function up(): void
    {
        Schema::table('order_events', function (Blueprint $table) {
            $table->string('operation_key', 64)->nullable()->after('staff_name');
            $table->char('operation_payload_hash', 64)->nullable()->after('operation_key');
            $table->foreignId('payment_refund_id')->nullable()->after('operation_payload_hash')
                ->constrained('payment_refunds', indexName: 'oe_payment_refund_fk')
                ->restrictOnDelete();

            $table->unique(['order_id', 'operation_key'], 'oe_order_operation_key_unique');
        });

        if ($this->supportsCheck()) {
            DB::statement('ALTER TABLE order_events ADD CONSTRAINT oe_operation_hash_check CHECK ((operation_key IS NULL) = (operation_payload_hash IS NULL))');
        }
    }

    /** Refuses while any event carries a key or a refund reference: dropping the columns would destroy that record. */
    public function down(): void
    {
        $used = DB::table('order_events')->where(fn ($query) => $query->whereNotNull('operation_key')->orWhereNotNull('payment_refund_id'))->orderBy('id')->pluck('id');

        if ($used->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'order_events.id %s carries an operation key or a refund reference; rolling back would destroy it. Nothing was changed.',
                $used->take(20)->implode(', '),
            ));
        }

        // The UNIQUE (order_id, operation_key) is the index the order_id foreign key
        // runs on (MySQL dropped the FK's own, now redundant, index when it was
        // added), so the FK needs a plain index of its own BEFORE the unique can
        // go. Added first: DDL is not transactional (CLAUDE.md rule 6), and a
        // failure after this step leaves everything as it was, plus a harmless index.
        if (! Schema::hasIndex('order_events', 'oe_order_id_index')) {
            Schema::table('order_events', function (Blueprint $table) {
                $table->index('order_id', 'oe_order_id_index');
            });
        }

        if ($this->supportsCheck()) {
            DB::statement('ALTER TABLE order_events DROP CONSTRAINT oe_operation_hash_check');
        }

        Schema::table('order_events', function (Blueprint $table) {
            $table->dropUnique('oe_order_operation_key_unique');
            $table->dropForeign('oe_payment_refund_fk');
            $table->dropColumn(['payment_refund_id', 'operation_payload_hash', 'operation_key']);
        });
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
