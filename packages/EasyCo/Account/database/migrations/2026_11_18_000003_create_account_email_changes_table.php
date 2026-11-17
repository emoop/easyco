<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ACC-1: a PENDING email change (one per account): the new address and the hash of the confirmation token; the account's email changes only when the link is confirmed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_email_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts', indexName: 'acc_email_changes_account_fk')->cascadeOnDelete();
            $table->string('new_email', 254);
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('created_at');

            $table->unique('account_id', 'acc_email_changes_account_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_email_changes');
    }
};
