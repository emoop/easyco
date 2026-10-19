<?php

use App\Content\Menus\MenuSeeder;
use App\Storefront\Menu\MenuRecompiler;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * D3: seeds `main` (today's top-level categories, each a dropdown with its sub-categories) and `footer` (the pages flagged for the footer, in
     * their order), then compiles the menus once so the storefront looks the same right after the migration. Idempotent: an existing menu is
     * left alone, so a second run adds nothing. `pages.in_footer` / `footer_order` are NOT dropped here (a later cleanup migration will).
     */
    public function up(): void
    {
        MenuSeeder::run();
        app(MenuRecompiler::class)->now();
    }

    /** The menus are the merchant's data now: rolling back the seed removes nothing. */
    public function down(): void
    {
    }
};
