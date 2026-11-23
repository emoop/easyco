<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * CC-1: a new menu item kind, `consent` («Настройки за бисквитки»: a special link the merchant adds to a menu, no URL typed). The type CHECK (`menu_items_type_check`, CLAUDE.md rule 2) is
     * REPLACED by the final kinds of the menus (Menu3Migrator: link, category, page, product, column) plus the new one. No new table, no new column.
     *
     * Idempotent (rule 6): a CHECK that already names `consent` is left alone; one that is missing is added; an OLD one is dropped and replaced. A row whose type is none of the allowed kinds would make
     * the new CHECK fail half way (MySQL DDL is not transactional), so it is looked for FIRST and the migration stops with a clear message instead of leaving a table without its CHECK.
     */
    private const KINDS = ['link', 'category', 'page', 'product', 'column', 'consent'];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $clause = $this->currentClause();

        if ($clause !== null && str_contains($clause, 'consent')) {
            return;
        }

        $stray = DB::table('menu_items')->whereNotIn('type', self::KINDS)->distinct()->pluck('type')->all();

        if ($stray !== []) {
            throw new RuntimeException('menu_items holds a type this CHECK would refuse ('.implode(', ', $stray).'): fix those rows first; the old CHECK was left as it is.');
        }

        if ($clause !== null) {
            DB::statement('ALTER TABLE menu_items DROP CHECK menu_items_type_check');
        }

        DB::statement("ALTER TABLE menu_items ADD CONSTRAINT menu_items_type_check CHECK (type IN ('".implode("','", self::KINDS)."'))");
    }

    /** The menus are the merchant's data now: rolling back changes nothing (a `consent` item would make the narrower CHECK fail). */
    public function down(): void
    {
    }

    private function currentClause(): ?string
    {
        $row = DB::table('information_schema.check_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('constraint_name', 'menu_items_type_check')
            ->selectRaw('check_clause as clause')
            ->first();

        return $row === null ? null : (string) $row->clause;
    }
};
