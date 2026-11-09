<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P5: a promotion can be flagged "public" so the cart lists its code, with a short customer-facing
     * description. Every existing row stays non-public (default false, no description).
     */
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->after('status');
            $table->string('public_description', 120)->nullable()->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn(['is_public', 'public_description']);
        });
    }
};
