<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DS-3.1: the footer is made of the footer builder's blocks (and the `footer` menu), not of a flag on a page: `pages.in_footer` and `pages.footer_order` are dropped, first the index
     * `pages_status_footer_index` that holds them. A page that had `in_footer = 1` stays published and reachable; the merchant places it with a footer menu block. Idempotent (CLAUDE.md rule 6).
     */
    public function up(): void
    {
        if (Schema::hasIndex('pages', 'pages_status_footer_index')) {
            Schema::table('pages', fn (Blueprint $table) => $table->dropIndex('pages_status_footer_index'));
        }

        foreach (['in_footer', 'footer_order'] as $column) {
            if (Schema::hasColumn('pages', $column)) {
                Schema::table('pages', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    /** The ORIGINAL definitions of 2026_10_17_000002 (same order, defaults and index name). The values are not restored: every row comes back with the defaults. */
    public function down(): void
    {
        if (! Schema::hasColumn('pages', 'in_footer')) {
            Schema::table('pages', fn (Blueprint $table) => $table->boolean('in_footer')->default(false)->after('noindex'));
        }

        if (! Schema::hasColumn('pages', 'footer_order')) {
            Schema::table('pages', fn (Blueprint $table) => $table->unsignedSmallInteger('footer_order')->default(0)->after('in_footer'));
        }

        if (! Schema::hasIndex('pages', 'pages_status_footer_index')) {
            Schema::table('pages', fn (Blueprint $table) => $table->index(['status', 'in_footer', 'footer_order'], 'pages_status_footer_index'));
        }
    }
};
