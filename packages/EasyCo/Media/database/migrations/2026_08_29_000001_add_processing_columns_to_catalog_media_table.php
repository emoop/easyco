<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brings catalog_media in line with the new EasyCo\Media\MediaAsset
 * domain model — see media-domain-design.md §9.
 *
 * `url` DROPPED, replaced by `disk`+`path`: the old column was an
 * opaque pointer treated as owned by whatever storage/CDN system held
 * the bytes; the new model holds a disk/path pair instead and computes
 * a URL at read time — the domain layer never touches Storage directly
 * or persists a hardcoded absolute URL (§5).
 *
 * NO DEFAULT VALUES on the new NOT NULL columns (`disk`, `path`,
 * `processing_status`): confirmed via a real `SELECT COUNT(*) FROM
 * catalog_media` against the dev database immediately before writing
 * this migration — 0 rows exist (no domain class or HTTP surface has
 * ever written to this table, per §1), so there is no existing data to
 * backfill a default for. `processing_status`'s initial value is
 * mode-specific (PENDING for IMAGE/SOCIAL_IMAGE, READY for VIDEO/
 * SOCIAL_VIDEO, §4/§8) and is decided by MediaAsset::create() at
 * write time, not by a single DB-level default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_media', function (Blueprint $table) {
            $table->dropColumn('url');

            $table->string('disk')->after('type');
            $table->string('path')->after('disk');
            $table->string('processing_status')->after('alt_text');
            $table->text('processing_failure_reason')->nullable()->after('processing_status');
            $table->json('variants')->nullable()->after('processing_failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_media', function (Blueprint $table) {
            $table->dropColumn(['disk', 'path', 'processing_status', 'processing_failure_reason', 'variants']);

            $table->string('url')->after('type');
        });
    }
};
