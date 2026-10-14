<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `order_invoice_details` — the row's EXISTENCE is the invoice request (order-context-design.md section 2.2, stage C2-A): a snapshot of
     * a billing block the customer typed at checkout, and never a document. There is no `requested` boolean, no status, no invoice number
     * and no tax line, so both futures of the invoicing question stay open (design section 1).
     *
     * Keys exactly as the design: `oid_order_id_foreign` (RESTRICT) and `oid_order_id_unique` (at most one request per order).
     * `country` is a plain string like `orders.country`; the ISO shape is a validation rule, never a column width. `invoice_email` is a
     * stored copy (it defaults to the order's email but may be an accountant's). `country_rule_version` names the version of the country's
     * rule set that accepted the row (NULL when no rule applied).
     *
     * CLAUDE.md rule 2 (MySQL/MariaDB only), explicit short names (rule 5): `oid_recipient_type_check` (a company or an individual) and
     * `oid_individual_no_tax_id_check` (an INDIVIDUAL never carries a tax id: the design refuses one rather than dropping it silently).
     * Idempotent (rule 6), in up() and in down().
     */
    public const CHECKS = [
        'oid_recipient_type_check' => "recipient_type IN ('company','individual')",
        'oid_individual_no_tax_id_check' => "recipient_type = 'company' OR tax_id IS NULL",
    ];

    public function up(): void
    {
        if (! Schema::hasTable('order_invoice_details')) {
            Schema::create('order_invoice_details', function (Blueprint $table) {
                $table->id();

                $table->foreignId('order_id')
                    ->constrained('orders', indexName: 'oid_order_id_foreign')
                    ->restrictOnDelete();
                $table->unique('order_id', 'oid_order_id_unique');

                $table->timestamp('requested_at');
                $table->string('recipient_type');

                $table->string('legal_name');
                $table->string('tax_id')->nullable();
                $table->string('company_registration_number')->nullable();
                $table->string('representative')->nullable();

                $table->string('country');
                $table->string('city');
                $table->string('postal_code');
                $table->string('address_line_1');
                $table->string('address_line_2')->nullable();

                $table->string('invoice_email');
                $table->string('country_rule_version')->nullable();

                $table->timestamps();
            });
        }

        if ($this->supportsCheck()) {
            foreach (self::CHECKS as $name => $expression) {
                if (! $this->hasCheck('order_invoice_details', $name)) {
                    DB::statement("ALTER TABLE order_invoice_details ADD CONSTRAINT {$name} CHECK ({$expression})");
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_invoice_details');
    }

    private function hasCheck(string $table, string $name): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $name)
            ->where('constraint_type', 'CHECK')
            ->exists();
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
