<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every foreign key and index below is given an EXPLICIT short name
     * (prefix "os_sale_lines_" instead of the full table name) up front.
     * operational_sales_sale_lines is a long table name, and several of
     * Laravel's auto-generated names on it exceed MySQL's 64-character
     * identifier limit — e.g. the auto name for
     * originating_reservation_line_id's FK alone would be 68 characters.
     * See catalog-domain-design.md §7 for the same lesson learned the
     * hard way on Catalog's own migrations.
     */
    public function up(): void
    {
        Schema::create('operational_sales_sale_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('transaction_id')
                ->constrained('operational_sales_transactions', indexName: 'os_sale_lines_transaction_id_foreign')
                ->restrictOnDelete();

            $table->foreignId('client_id')
                ->constrained('operational_sales_clients', indexName: 'os_sale_lines_client_id_foreign')
                ->restrictOnDelete();

            // NOT a foreign key, by design — Operational Sales must never
            // depend on Catalog directly (operational-sales-domain-design.md
            // §1). Mirrors Catalog\Variation::priceableId()'s own contract:
            // a plain string other domains resolve against externally.
            // Null only for SHIPPING / INSTALLMENT_PAYMENT pseudo-lines —
            // see SaleLine's own constructor validation.
            $table->string('priceable_id')->nullable();

            // sale | reservation | refund | shipping | installment_payment
            // — see EasyCo\OperationalSales\Enums\SaleLineType.
            $table->string('type');

            // pending | completed | cancelled — see
            // EasyCo\OperationalSales\Enums\SaleLineStatus.
            $table->string('status');

            $table->unsignedInteger('quantity');

            // Money's actual public API (minorValue(): int, currency():
            // Currency) — no easyco/pricing migrations exist yet to
            // mirror, so this stores the same two primitives Money itself
            // exposes: a signed bigint of minor units (never a float —
            // see design doc §3.1) plus a 3-letter currency code.
            $table->bigInteger('amount_minor');
            $table->char('amount_currency', 3);

            $table->bigInteger('profit_minor');
            $table->char('profit_currency', 3);

            // When this row was written, vs. when the event it records
            // actually happened (e.g. the original reservation date for a
            // line later settled) — design doc §3.5. Never nullable: every
            // SaleLine has both, always.
            $table->timestamp('recorded_at');
            $table->timestamp('effective_at');

            // Set only on a REFUND line, pointing at the SaleLine it
            // refunds. nullOnDelete, not restrict: a correction line must
            // not be destroyed if its original somehow is, but must also
            // not hard-block deleting the original.
            $table->foreignId('originating_sale_line_id')->nullable()
                ->constrained('operational_sales_sale_lines', indexName: 'os_sale_lines_orig_sale_line_id_foreign')
                ->nullOnDelete();

            // Set only on a settled-reservation SALE line, pointing at the
            // RESERVATION line it settles. Same nullOnDelete reasoning as
            // above.
            $table->foreignId('originating_reservation_line_id')->nullable()
                ->constrained('operational_sales_sale_lines', indexName: 'os_sale_lines_orig_reservation_line_id_foreign')
                ->nullOnDelete();

            // Set only when this line is a RESERVATION attached to an
            // InstallmentPlan, or an INSTALLMENT_PAYMENT belonging to one.
            $table->foreignId('installment_plan_id')->nullable()
                ->constrained('operational_sales_installment_plans', indexName: 'os_sale_lines_installment_plan_id_foreign')
                ->restrictOnDelete();

            $table->timestamps();

            // Never hard-deleted — a SaleLine is a historical, factual
            // record (design doc §3.2); mirrors Catalog\Variation's own
            // softDeletes() posture exactly.
            $table->softDeletes();

            $table->index('transaction_id', 'os_sale_lines_transaction_id_index');

            // The hot path: "find this client's active reservations" —
            // e.g. InstallmentPlan::attachReservedLine() candidates.
            $table->index(['client_id', 'type', 'status'], 'os_sale_lines_client_type_status_index');

            $table->index('installment_plan_id', 'os_sale_lines_installment_plan_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_sales_sale_lines');
    }
};
