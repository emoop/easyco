<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * See packages/EasyCo/documents/site-settings-design.md §4/§7. Lives in
 * the root app's database/migrations/, not a package's own — Site
 * Settings is deliberately not an EasyCo\* domain package (§3).
 *
 * No `type` column, no schema-level validation of a given key's value
 * — the storage layer stays deliberately dumb (§4); whichever feature
 * defines a key owns the meaning/validation of its value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
