<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every unique index below is given an EXPLICIT short name (prefix
     * "pp_lists_" instead of the full table name) up front, mirroring
     * operational_sales_sale_lines' own "os_sale_lines_" convention
     * exactly — see that migration's docblock, and catalog-domain-design.md
     * §7, for the same MySQL 64-character identifier limit lesson this
     * package would otherwise re-learn the hard way.
     */
    public function up(): void
    {
        Schema::create('pricing_price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // fixed_items | percentage_off_regular — see
            // EasyCo\Pricing\Enums\PriceListMode.
            $table->string('mode');

            // Basis points, 2000 = 20% — same convention as PriceList.php
            // itself (see that class's own docblock, which parallels
            // Price's tax-rate representation). Only meaningful when
            // mode = percentage_off_regular; null for fixed_items.
            $table->unsignedInteger('percentage_basis_points')->nullable();

            $table->integer('priority');
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();

            // active | inactive — see EasyCo\Pricing\Enums\PriceListStatus.
            $table->string('status');

            // true only for the two reserved system lists ("Regular
            // Prices", "Manual Sale" — pricing-persistence-domain-design.md
            // §4.5).
            $table->boolean('is_system')->default(false);

            // Deterministic hash of this list's PriceListScope set
            // (pricing-persistence-domain-design.md §4.7) — a plain
            // column here, computed in the application layer by a
            // PriceListSignature class (not part of this migration; a
            // separate, later prompt). Direct precedent:
            // Catalog\VariationSignature / catalog_variations.
            // attribute_signature — same reasoning applies unchanged:
            // the source data (this list's scope rows) lives in a
            // *child* table (pricing_price_list_scopes), not as inline
            // columns here, so it cannot be a MySQL/Postgres STORED
            // GENERATED COLUMN (those can only read other columns in the
            // same row).
            $table->char('scope_signature', 64);

            $table->timestamps();

            // Per §4.7: catches two lists with the EXACT identical scope
            // condition set at the same priority — a real,
            // race-condition-safe guarantee, not an application-layer
            // guess. Does NOT catch genuine partial-overlap between
            // different scope sets (e.g. BRAND:Guess + CATEGORY:Shirts
            // both at the same priority) — that is §4.7's explicitly
            // deferred limitation, not a bug in this constraint.
            $table->unique(['priority', 'scope_signature'], 'pp_lists_priority_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_price_lists');
    }
};
