<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACC-1, additive only. `email_verified_at`: NULL = unverified (every existing row stays unverified; the WooCommerce import will set it later).
 * `session_version`: stored in the session at login and compared on every `auth:customer` request; bumped on a password reset / change and an email change,
 * which kills every other session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->unsignedInteger('session_version')->default(0)->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['email_verified_at', 'session_version']);
        });
    }
};
