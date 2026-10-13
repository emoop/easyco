<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkout form v2, stage C1d-B (checkout-form-v2-design.md section 6, order-context-design.md section 2.3 and 9): what the customer
     * ticked, as create-time facts on the order and on its placement snapshot (the snapshot is what was agreed at placement).
     *
     *  - `terms_accepted_at` TIMESTAMP NULL and `terms_version` VARCHAR(32) NULL: the proof of the terms acceptance (the placement
     *    instant and the version the page displayed);
     *  - `confirmation_requested` BOOLEAN NULL (order-context 2.3: never sent by the storefront, D12; an API caller may send it);
     *  - `call_before_shipping` BOOLEAN NULL;
     *  - `marketing_consent` BOOLEAN NULL and `marketing_consent_text` VARCHAR(500) NULL: the exact sentence the customer ticked.
     *
     * ALL NULLABLE: NULL means "not asked / placed before the field existed" (a NOT NULL DEFAULT 0 would invent a fact for every
     * existing order). No index: nothing filters by them.
     *
     * CLAUDE.md rule 2, MySQL/MariaDB only, explicit short names (rule 5): `ord_terms_proof_check` / `ops_terms_proof_check` (21
     * characters) - the version is present exactly when the acceptance time is; `ord_consent_text_check` / `ops_consent_text_check`
     * (22) - the sentence is present exactly when the consent is TRUE, and never blank. Every existing row satisfies both (all NULL).
     *
     * Idempotent (rule 6): a column or CHECK that is already there is left alone, in up() and in down().
     */
    public const COLUMNS = [
        'terms_accepted_at' => 'timestamp',
        'terms_version' => 32,
        'confirmation_requested' => 'boolean',
        'call_before_shipping' => 'boolean',
        'marketing_consent' => 'boolean',
        'marketing_consent_text' => 500,
    ];

    public const TABLES = ['orders' => 'ord', 'order_placement_snapshots' => 'ops'];

    /** @return array<string, string> check name => expression */
    public static function checks(string $prefix): array
    {
        return [
            "{$prefix}_terms_proof_check" => '(terms_accepted_at IS NULL) = (terms_version IS NULL) AND (terms_version IS NULL OR TRIM(terms_version) <> \'\')',
            "{$prefix}_consent_text_check" => '(marketing_consent_text IS NOT NULL) = (COALESCE(marketing_consent, 0) = 1) AND (marketing_consent_text IS NULL OR TRIM(marketing_consent_text) <> \'\')',
        ];
    }

    public function up(): void
    {
        foreach (self::TABLES as $table => $prefix) {
            foreach (self::COLUMNS as $column => $type) {
                if (! Schema::hasColumn($table, $column)) {
                    Schema::table($table, function (Blueprint $blueprint) use ($column, $type) {
                        match (true) {
                            $type === 'timestamp' => $blueprint->timestamp($column)->nullable(),
                            $type === 'boolean' => $blueprint->boolean($column)->nullable(),
                            default => $blueprint->string($column, $type)->nullable(),
                        };
                    });
                }
            }

            if ($this->supportsCheck()) {
                foreach (self::checks($prefix) as $name => $expression) {
                    if (! $this->hasCheck($table, $name)) {
                        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
                    }
                }
            }
        }
    }

    /** The checks first, then the columns. */
    public function down(): void
    {
        foreach (self::TABLES as $table => $prefix) {
            if ($this->supportsCheck()) {
                foreach (array_keys(self::checks($prefix)) as $name) {
                    if ($this->hasCheck($table, $name)) {
                        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
                    }
                }
            }

            foreach (array_keys(self::COLUMNS) as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, function (Blueprint $blueprint) use ($column) {
                        $blueprint->dropColumn($column);
                    });
                }
            }
        }
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
