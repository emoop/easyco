<?php

use App\Content\Menus\Menu3Migrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * MENU-3 step 2: the DATA step (App\Content\Menus\Menu3Migrator, which the tests call too): a mega item's first-level items become COLUMNS, `mega_columns` is set, more than 4
     * columns are merged into the 4th, the type CHECK is narrowed to link | category | page | product | column and the menus are compiled again. Idempotent. The counts go to the log
     * (`menu3.migrated`).
     */
    public function up(): void
    {
        Log::info('menu3.migrated', Menu3Migrator::run());
    }

    /** The menus are the merchant's data now: rolling back changes nothing. */
    public function down(): void
    {
    }
};
