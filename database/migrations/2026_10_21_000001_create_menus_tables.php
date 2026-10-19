<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menus (D3, storefront-customization-design.md 5 and 14): the merchant's own navigation, a SEPARATE tool (nothing is added to a menu
     * because a category exists). `menus.key` is the stable name the header and the footer refer to; `main` and `footer` are seeded by the next
     * migration and cannot be deleted. An item is a link, a category, a page, a column (mega menu only) or an image (inside a column). A
     * category / page target has NO foreign key on purpose: a deleted target is skipped when the menus are compiled, never a broken menu.
     *
     * CLAUDE.md rule 2, MySQL/MariaDB only, explicit short names (rule 5):
     *  - `menu_items_type_check` (21): link | category | page | column | image;
     *  - `menu_items_submenu_check` (24): none | dropdown | mega;
     *  - `menu_items_limit_check` (22): children_limit between 1 and 50;
     *  - `menus_key_check` (15): lower-case letters, digits and hyphens, 1-40 characters.
     * The depth / type-by-position / count rules need the whole tree: they live in App\Content\Menus\MenuValidator, the only writer's gate.
     *
     * Idempotent (rule 6): a table or CHECK that is already there is left alone. MySQL DDL is not transactional: after a failed run look at
     * what exists before re-running.
     */
    public const CHECKS = [
        'menus' => [
            'menus_key_check' => "`key` REGEXP '^[a-z0-9-]{1,40}$'",
        ],
        'menu_items' => [
            'menu_items_type_check' => "type IN ('link','category','page','column','image')",
            'menu_items_submenu_check' => "submenu IN ('none','dropdown','mega')",
            'menu_items_limit_check' => 'children_limit BETWEEN 1 AND 50',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('menus')) {
            Schema::create('menus', function (Blueprint $table) {
                $table->id();
                $table->string('key', 40)->unique('menus_key_unique');
                $table->string('name', 100);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('menu_items')) {
            Schema::create('menu_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('menu_items')->cascadeOnDelete();
                $table->unsignedSmallInteger('sort')->default(0);
                $table->string('type', 16);
                $table->string('label', 120)->nullable();
                $table->string('url', 500)->nullable();
                $table->unsignedBigInteger('target_id')->nullable();
                $table->string('submenu', 16)->default('none');
                $table->boolean('with_children')->default(false);
                $table->unsignedSmallInteger('children_limit')->default(12);
                $table->unsignedBigInteger('media_asset_id')->nullable();
                $table->string('image_alt', 200)->nullable();
                $table->string('caption', 80)->nullable();
                $table->boolean('new_tab')->default(false);
                $table->boolean('visible')->default(true);
                $table->timestamps();

                $table->index(['menu_id', 'parent_id', 'sort'], 'mi_menu_parent_sort_index');
            });
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (self::CHECKS as $table => $checks) {
                foreach ($checks as $name => $expression) {
                    if (! $this->hasCheck($table, $name)) {
                        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menus');
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
};
