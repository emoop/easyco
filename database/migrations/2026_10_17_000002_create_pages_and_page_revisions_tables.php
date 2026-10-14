<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pages (P1, pages-design.md section 2): the shop's static content pages (terms, privacy, delivery, returns, contacts) and the
     * append-only history of their text. An APP-LEVEL migration: the pages live in the app layer (App\\Content), not in a domain package.
     *
     *  - `pages.body` and `page_revisions.body` hold SANITIZED HTML only (the writer cleans before saving; the storefront cleans again at
     *    render). `pages.revision` is the number of the current revision and starts at 1.
     *  - A revision row is written only when the sanitized title or body changes; revisions are never updated or deleted
     *    (`page_revisions.page_id` is a RESTRICT foreign key). `staff_id` has no FK: staff can be removed, the history stays.
     *
     * CLAUDE.md rule 2, MySQL/MariaDB only, explicit short names (rule 5):
     *  - `pages_status_check` (18 characters): draft | published;
     *  - `pages_slug_check` (16): lower-case letters of any script, digits and single hyphens, no leading / trailing hyphen
     *    (the rule of PageSlug, pages-design.md 3.2); the column is 120 characters;
     *  - `pages_revision_check` (20): revision >= 1.
     * The other drivers keep the writer's own rules.
     *
     * Idempotent (rule 6): a table or CHECK that is already there is left alone, in up() and in down(). MySQL DDL is not transactional:
     * after a failed run look at what exists before re-running.
     */
    public const CHECKS = [
        'pages_status_check' => "status IN ('draft','published')",
        // BINARY LOWER(): the column's collation is case-insensitive, so equality alone would let an upper-case letter through.
        'pages_slug_check' => "BINARY slug = BINARY LOWER(slug) AND slug REGEXP '^[[:alnum:]]+(-[[:alnum:]]+)*$'",
        'pages_revision_check' => 'revision >= 1',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('pages')) {
            Schema::create('pages', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 120)->unique('pages_slug_unique');
                $table->string('title', 200);
                $table->mediumText('body');
                $table->string('status', 16);
                $table->unsignedInteger('revision');
                $table->string('meta_title', 200)->nullable();
                $table->string('meta_description', 320)->nullable();
                $table->boolean('noindex')->default(false);
                $table->boolean('in_footer')->default(false);
                $table->unsignedSmallInteger('footer_order')->default(0);
                $table->timestamp('published_at')->nullable();
                $table->timestamps();

                // The footer and the selects read "published pages": one index for that scan.
                $table->index(['status', 'in_footer', 'footer_order'], 'pages_status_footer_index');
            });
        }

        if (! Schema::hasTable('page_revisions')) {
            Schema::create('page_revisions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('page_id')->constrained('pages', indexName: 'prev_page_id_foreign')->restrictOnDelete();
                $table->unsignedInteger('revision');
                $table->string('title', 200);
                $table->mediumText('body');
                $table->unsignedBigInteger('staff_id')->nullable();
                $table->timestamp('created_at');

                $table->unique(['page_id', 'revision'], 'prev_page_revision_unique');
            });
        }

        if ($this->supportsCheck()) {
            foreach (self::CHECKS as $name => $expression) {
                if (! $this->hasCheck('pages', $name)) {
                    DB::statement("ALTER TABLE pages ADD CONSTRAINT {$name} CHECK ({$expression})");
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('page_revisions');
        Schema::dropIfExists('pages');
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
