<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A separate migration adding one nullable column — the original
     * create-table migration is never edited, same convention every
     * other added column in this project follows.
     *
     * attempted_at RECORDS WHEN THE ADAPTER ANSWERED, not when the
     * payment "resolved" — see EasyCo\Payment\Payment's own class
     * docblock for the full reasoning and the two previously-
     * indistinguishable states this separates (status PENDING +
     * attempted_at NULL vs. status PENDING + attempted_at SET).
     *
     * Existing rows get NULL, which is honest: for any Payment written
     * before this change, we genuinely do not know when its adapter
     * answered.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('attempted_at')->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('attempted_at');
        });
    }
};
