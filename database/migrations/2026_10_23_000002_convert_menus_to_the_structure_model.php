<?php

use App\Content\Menus\Menu2Migrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * MENU-2 step 2: the DATA step (all of it in App\Content\Menus\Menu2Migrator, which the tests call too). D3 columns become headings, a mega item's pictures become ONE
     * HTML block, `with_children` becomes explicit items, `menus.locations` is seeded, the header layouts lose `menu.source`, the type CHECK is narrowed to the final five
     * types and the menus are compiled again. Idempotent: a second run finds nothing to convert. The counts go to the log (`menu2.migrated`) and are returned by run().
     */
    public function up(): void
    {
        Log::info('menu2.migrated', Menu2Migrator::run());
    }

    /** The menus are the merchant's data now: rolling back changes nothing. */
    public function down(): void
    {
    }
};
