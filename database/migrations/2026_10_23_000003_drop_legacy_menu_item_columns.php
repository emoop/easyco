<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY = ['with_children', 'children_limit', 'media_asset_id', 'image_alt', 'caption'];

    /**
     * MENU-2 step 3, AFTER the data step: the columns of the D3 model that no longer mean anything are dropped (`with_children`, `children_limit`, `media_asset_id`,
     * `image_alt`, `caption`) together with `menu_items_limit_check`. REFUSES to run while a row still needs the data step (a `column` / `image` row, or a category
     * with `with_children` on): dropping the columns then would lose what the merchant built. Idempotent.
     */
    public function up(): void
    {
        if (Schema::hasColumn('menu_items', 'with_children')
            && DB::table('menu_items')->where(fn ($q) => $q->whereIn('type', ['column', 'image'])->orWhere('with_children', 1))->exists()) {
            throw new RuntimeException('menu_items still holds D3 rows (column / image / with_children): run the previous migration first.');
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())->where('table_name', 'menu_items')->where('constraint_name', 'menu_items_limit_check')->exists()) {
            DB::statement('ALTER TABLE menu_items DROP CHECK menu_items_limit_check');
        }

        foreach (self::LEGACY as $column) {
            if (Schema::hasColumn('menu_items', $column)) {
                Schema::table('menu_items', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    /** The menus are the merchant's data now: rolling back changes nothing. */
    public function down(): void
    {
    }
};
