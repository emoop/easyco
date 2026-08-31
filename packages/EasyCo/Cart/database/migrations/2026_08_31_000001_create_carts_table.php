<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * cascadeOnDelete() here, unlike stock_levels'/catalog_variations'
     * restrictOnDelete() — see cart-domain-design.md §2/§Persistence.
     * A cart is disposable working state with no historical value; it
     * should not outlive the account it belongs to.
     *
     * account_id/session_token are both nullable, and each unique on
     * its own — at most one cart per account, one per token. The real
     * "exactly one of the two, never both, never neither" guarantee
     * lives in the Cart domain class's constructor, not here — there
     * is no portable DB-level XOR check.
     */
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->unique()
                ->constrained('accounts')->cascadeOnDelete();
            $table->string('session_token')->nullable()->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
