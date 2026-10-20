<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * D3 seeded `main` and `footer` here. MENU-2 moved the seeding into App\Content\Menus\Menu2Migrator (2026_10_23_000002), which runs once the menu tables have
     * their final columns: on a database that already ran this migration nothing changes, on a fresh one the menus are made there. This migration does nothing now,
     * so it stays in the chain without ever writing a row the new tables could not hold.
     */
    public function up(): void
    {
    }

    /** The menus are the merchant's data now: rolling back removes nothing. */
    public function down(): void
    {
    }
};
