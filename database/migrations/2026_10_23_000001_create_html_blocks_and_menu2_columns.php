<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MENU-2 step 1 (menu-editor-v2-design.md 3): the table of HTML blocks and the new columns of `menu_items` (`html_block_id`, `badge_text`, `badge_color`).
     * The type CHECK is WIDENED here to the old and the new types together (`product`, `heading` added), because the D3 rows (`column`, `image`) still exist until the
     * next migration has converted them; that migration narrows it to the final five. Nothing is deleted here.
     *
     *  - `html_block_id` has NO foreign key on purpose (a deleted block is skipped when the menus are compiled, never a broken menu; the resource refuses the delete anyway);
     *  - `menu_items_badge_check` (22): the colour is a preset name or `#RRGGBB`;
     *  - the text length (16) is the column's own width.
     *
     * Idempotent (CLAUDE.md rule 6): a table, column or CHECK that is already there is left alone.
     */
    public function up(): void
    {
        if (! Schema::hasTable('html_blocks')) {
            Schema::create('html_blocks', function (Blueprint $table) {
                $table->id();
                $table->string('title', 120);
                $table->mediumText('body');
                $table->timestamps();
            });
        }

        foreach ([
            'html_block_id' => fn (Blueprint $table) => $table->unsignedBigInteger('html_block_id')->nullable()->after('submenu'),
            'badge_text' => fn (Blueprint $table) => $table->string('badge_text', 16)->nullable()->after('html_block_id'),
            'badge_color' => fn (Blueprint $table) => $table->char('badge_color', 7)->nullable()->after('badge_text'),
        ] as $column => $add) {
            if (! Schema::hasColumn('menu_items', $column)) {
                Schema::table('menu_items', fn (Blueprint $table) => $add($table));
            }
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->replaceCheck('menu_items_type_check', "type IN ('link','category','page','product','heading','column','image')");

            if (! $this->hasCheck('menu_items_badge_check')) {
                DB::statement("ALTER TABLE menu_items ADD CONSTRAINT menu_items_badge_check CHECK (badge_color REGEXP '^(#[0-9a-f]{6}|[a-z]{3,6})$')");
            }
        }
    }

    /** The menus are the merchant's data now: rolling back changes nothing (the data step cannot be undone, the backup file can). */
    public function down(): void
    {
    }

    private function replaceCheck(string $name, string $expression): void
    {
        if ($this->hasCheck($name)) {
            DB::statement("ALTER TABLE menu_items DROP CHECK {$name}");
        }

        DB::statement("ALTER TABLE menu_items ADD CONSTRAINT {$name} CHECK ({$expression})");
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
