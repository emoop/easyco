<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `order_attributions` — one row per order, always written (order-context-design.md section 2.1, stage C2-A). An APP-LEVEL migration:
     * a context table that belongs to no domain package (it sits beside `order_events`), so `EasyCo\Order` knows nothing of it.
     *
     * C2-A WRITES ONLY: the order id, `source_type` = unknown, `capture_status` = skipped_no_data, the consent snapshot, the request facts
     * (`ip`, `user_agent`, `device_type`) and `captured_at` (the placement instant). Every touch and visit column stays NULL until the
     * consent-aware client-side capture exists (design amendment, section 4): the columns are here now so that stage adds no migration.
     *
     * `ip` IS PERSONAL DATA WITH ITS OWN CLOCK (section 7): its purpose is fraud, security and dispute evidence, NEVER marketing or
     * profiling, and it is replaced by its anonymised form after `privacy.ip_full_retention_days` (`ip_anonymised_at` is the record that it
     * was ever there). Only ORDER_MANAGE may read the full value.
     *
     * Keys and names exactly as the design: `attr_order_id_foreign` (RESTRICT) and `attr_order_id_unique` on the same column (the FK proves
     * the order exists, the unique proves AT MOST ONE origin record per order), `attr_source_type_index`, `attr_visitor_id_index`,
     * `attr_captured_at_index`. CLAUDE.md rule 2 (MySQL/MariaDB only): a CHECK per enum column, short explicit names (rule 5), every one
     * well under 64 characters. Idempotent (rule 6): a table or CHECK that is already there is left alone, in up() and in down().
     */
    public const CHECKS = [
        'attr_source_type_check' => "source_type IN ('utm','organic','referral','direct','ai_assistant','social','email','admin','agent','unknown')",
        'attr_capture_status_check' => "capture_status IN ('captured','skipped_no_consent','skipped_no_data')",
        'attr_consent_mode_check' => "consent_mode IN ('strict','notice','off')",
        'attr_analytics_consent_check' => "analytics_consent IN ('granted','denied','unknown')",
        'attr_consent_source_check' => "consent_source IN ('banner','api','not_asked')",
        'attr_device_type_check' => "device_type IS NULL OR device_type IN ('desktop','mobile','tablet','bot','unknown')",
    ];

    public function up(): void
    {
        if (! Schema::hasTable('order_attributions')) {
            Schema::create('order_attributions', function (Blueprint $table) {
                $table->id();

                $table->foreignId('order_id')
                    ->constrained('orders', indexName: 'attr_order_id_foreign')
                    ->restrictOnDelete();
                $table->unique('order_id', 'attr_order_id_unique');

                // What the resolver decided, and why the record is what it is.
                $table->string('source_type');
                $table->string('capture_status');

                // The LAST touch — the one that produced this order.
                $table->string('last_utm_source')->nullable();
                $table->string('last_utm_medium')->nullable();
                $table->string('last_utm_campaign')->nullable();
                $table->string('last_utm_content')->nullable();
                $table->string('last_utm_term')->nullable();
                $table->string('last_utm_id')->nullable();
                $table->string('referrer_host')->nullable();
                $table->string('referrer_url', 500)->nullable();
                $table->string('click_id_type', 32)->nullable();
                $table->string('click_id_value')->nullable();
                $table->string('ai_platform', 64)->nullable();

                // The visit itself, as facts — never a browsing history.
                $table->string('landing_page_url', 500)->nullable();
                $table->unsignedSmallInteger('session_pages')->nullable();
                $table->unsignedSmallInteger('session_count')->nullable();
                $table->string('device_type', 16)->nullable();
                $table->string('user_agent')->nullable();
                $table->timestamp('last_touch_at')->nullable();

                // The FIRST touch.
                $table->string('first_source_type')->nullable();
                $table->string('first_utm_source')->nullable();
                $table->string('first_utm_medium')->nullable();
                $table->string('first_utm_campaign')->nullable();
                $table->string('first_landing_page_url', 500)->nullable();
                $table->timestamp('first_touch_at')->nullable();

                // Identity and consent. `account_id` has NO foreign key on purpose: a snapshot that survives the account's own life.
                $table->char('visitor_id', 36)->nullable();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->string('consent_mode', 16);
                $table->string('analytics_consent', 16);
                $table->string('consent_source', 24);

                // Personal data with its own clock.
                $table->string('ip', 45)->nullable();
                $table->timestamp('ip_anonymised_at')->nullable();

                $table->timestamp('captured_at');
                $table->timestamps();

                $table->index('source_type', 'attr_source_type_index');
                $table->index('visitor_id', 'attr_visitor_id_index');
                $table->index('captured_at', 'attr_captured_at_index');
            });
        }

        if ($this->supportsCheck()) {
            foreach (self::CHECKS as $name => $expression) {
                if (! $this->hasCheck('order_attributions', $name)) {
                    DB::statement("ALTER TABLE order_attributions ADD CONSTRAINT {$name} CHECK ({$expression})");
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_attributions');
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
