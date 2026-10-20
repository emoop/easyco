<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MENU-3 step 1: `menu_items.mega_columns` (1-4, only on a top-level mega item; `menu_items_mega_cols_check`, 25) and the type CHECK WIDENED to the old and the new type
     * together (`column` added, `heading` still allowed) because the headings still exist until the next migration has turned them into columns. Idempotent (CLAUDE.md rule 6).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('menu_items', 'mega_columns')) {
            Schema::table('menu_items', fn (Blueprint $table) => $table->unsignedTinyInteger('mega_columns')->nullable()->after('html_block_id'));
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            if ($this->hasCheck('menu_items_type_check')) {
                DB::statement('ALTER TABLE menu_items DROP CHECK menu_items_type_check');
            }

            DB::statement("ALTER TABLE menu_items ADD CONSTRAINT menu_items_type_check CHECK (type IN ('link','category','page','product','heading','column'))");

            if (! $this->hasCheck('menu_items_mega_cols_check')) {
                DB::statement('ALTER TABLE menu_items ADD CONSTRAINT menu_items_mega_cols_check CHECK (mega_columns IS NULL OR mega_columns BETWEEN 1 AND 4)');
            }
        }
    }

    /** The menus are the merchant's data now: rolling back changes nothing. */
    public function down(): void
    {
    }

    private function hasCheck(string $name): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'menu_items')
            ->where('constraint_name', $name)
            ->where('constraint_type', 'CHECK')
            ->exists();
    }
};
