<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ACC-1: the customers' own reset tokens (never the staff `password_reset_tokens`): one row per email (a new request replaces the old), the token only as a hash. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 254)->primary();
            $table->string('token_hash', 64);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_password_reset_tokens');
    }
};
