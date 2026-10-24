<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ML-1a (media-library-design.md §2): what the «Медия» page shows about an original. All columns nullable: new uploads fill them, `php artisan media:backfill-meta` fills the old rows.
 * `uploaded_by` has no foreign key on purpose (a staff account may be deleted; the number stays as history). `missing_at` is set by the backfill when the original's file is not on the disk, so
 * the page can say «файлът липсва» without a disk call per card.
 *
 * Index names are explicit and short (CLAUDE.md rule 5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_media', function (Blueprint $table) {
            $table->string('original_name', 200)->nullable()->after('path');
            $table->string('mime', 50)->nullable()->after('original_name');
            $table->unsignedInteger('size_bytes')->nullable()->after('mime');
            $table->unsignedInteger('width')->nullable()->after('size_bytes');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->unsignedBigInteger('uploaded_by')->nullable()->after('height');
            $table->timestamp('missing_at')->nullable()->after('uploaded_by');

            $table->index(['type', 'created_at'], 'cm_type_created_idx');
            $table->index('original_name', 'cm_original_name_idx');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_media', function (Blueprint $table) {
            $table->dropIndex('cm_type_created_idx');
            $table->dropIndex('cm_original_name_idx');
            $table->dropColumn(['original_name', 'mime', 'size_bytes', 'width', 'height', 'uploaded_by', 'missing_at']);
        });
    }
};
