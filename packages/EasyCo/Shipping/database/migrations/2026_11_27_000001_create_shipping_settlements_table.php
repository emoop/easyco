<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The courier-neutral settlement list the customer searches (courier-integration-design.md §3.1, §3.3): the national backbone
 * (BgPostcodes grouped by region and normalised name) joined with each courier's own settlement ids.
 *
 * NEVER DELETED (§3.5, CLAUDE.md rule 4): points and orders may refer to a row; a rebuild only inserts and updates.
 *
 * IDENTITY `ss_identity_uq (country_code, region_code, name_key, part)`. `part` is the design's "split rule" made a key part:
 *   ''            the ordinary row of a region + name (Sofia city is ONE such row holding all its postcodes);
 *   '<postcode>'  a row split off because the same name matched two different courier ids of one courier (§3.3 rule 3), or
 *   'x<id>'       the same, when the postcode cannot tell the parts apart;
 *   's<id>'/'e<id>' a row made from a courier settlement that matched nothing (`source = courier`, flagged for the report).
 * `region_code` is '' for a courier row whose region name could not be read as exactly one oblast.
 *
 * `speedy_site_id` and `econt_city_id` are UNIQUE (one courier id belongs to at most one row; NULLs repeat freely), so the mapping
 * can never put one courier settlement on two rows, whatever the application does. `source` is CHECKed in a binary collation.
 * Names (rule 5) are all under 64 characters. Idempotent (rule 6).
 */
return new class extends Migration
{
    private const TABLE = 'shipping_settlements';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) {
                $table->id();
                $table->char('country_code', 2);
                $table->string('name', 120);
                $table->string('name_key', 120);
                $table->string('region_code', 8)->default('');
                $table->string('part', 24)->default('');
                $table->json('postcodes');
                $table->unsignedBigInteger('speedy_site_id')->nullable();
                $table->unsignedBigInteger('econt_city_id')->nullable();
                $table->string('source', 10);
                $table->boolean('is_split')->default(false);

                $table->unique(['country_code', 'region_code', 'name_key', 'part'], 'ss_identity_uq');
                $table->unique('speedy_site_id', 'ss_speedy_uq');
                $table->unique('econt_city_id', 'ss_econt_uq');
                $table->index(['country_code', 'name_key'], 'ss_name_key_idx');
            });
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && ! $this->hasCheck()) {
            DB::statement('ALTER TABLE '.self::TABLE." ADD CONSTRAINT ss_source_check CHECK (source COLLATE utf8mb4_bin IN ('postcodes', 'courier'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function hasCheck(): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('CONSTRAINT_NAME', 'ss_source_check')
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->exists();
    }
};
