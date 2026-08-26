<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs EasyCo\Catalog\Persistence\Eloquent\EloquentSkuSequenceRepository
     * (Contracts\SkuSequenceRepository), which
     * App\Providers\CatalogSkuGeneratorServiceProvider's
     * 'catalog.product.base_sku' Hook listener calls into — replacing the
     * previous DemoHooksServiceProvider proof-of-concept, which used an
     * in-memory PHP static (per-process, not persisted, not safe under
     * concurrent workers).
     *
     * This table lives in the Catalog package's own migrations, not the
     * root app's — it's Catalog domain data (the table name says so),
     * the same boundary already established for EasyCo\Pricing\
     * DefaultCurrency: the state-holder/persistence lives inside the
     * owning package, only the Laravel config-reading wiring
     * (`config('services.catalog.base_sku_sequence_start')`) legitimately
     * lives in app/. This migration previously shipped in the root app's
     * database/migrations/ by mistake and was moved here as a direct
     * follow-up correction — rolled back and re-applied from this
     * location, not left in both places.
     *
     * A single row holding the last-issued value. Concurrency safety
     * comes from HOW the row is read+incremented (see
     * EloquentSkuSequenceRepository::next()) — a DB::transaction()
     * wrapping a lockForUpdate() read, then an UPDATE, so the read-and-
     * increment is one atomic unit a second concurrent request cannot
     * interleave with. On MySQL/MariaDB this is a real row-level lock;
     * on SQLite (no row-level locking support) the same safety comes
     * from SQLite's own whole-database write serialization during a
     * transaction. This is the same constraint-first,
     * never-read-then-write principle already established in
     * catalog-domain-design.md §7 for unique-constraint collisions,
     * applied here to sequence generation instead.
     */
    public function up(): void
    {
        Schema::create('catalog_sku_sequence', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('last_value');
            $table->timestamps();
        });

        // Seeded so the FIRST generated base_sku equals the configured
        // start value exactly, not start+1: next() always increments
        // before reading, so the seed must be one less than the intended
        // first value.
        $start = (int) config('services.catalog.base_sku_sequence_start', 100000);

        DB::table('catalog_sku_sequence')->insert([
            'last_value' => $start - 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_sku_sequence');
    }
};
