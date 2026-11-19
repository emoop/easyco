<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ACC-2b: the default address of an account. `default_marker` is 1 for THE default and NULL for every other row, and
     * UNIQUE(account_id, default_marker) makes a second default for one account physically impossible (CLAUDE.md rule 2): NULLs are
     * distinct in a unique index, so any number of non-default rows and every guest row (account_id NULL) coexist.
     *
     * BACKFILL (deterministic): an account that already has addresses gets its most recently UPDATED address as the default, the
     * highest id among equal timestamps. The data step uses the query builder on purpose: it must not touch updated_at.
     *
     * Names are explicit and short (rule 5): `addr_account_default_unique` is 27 characters. Idempotent (rule 6): a column, an index or a
     * backfill that is already there is left alone, in up() and in down().
     */
    public const TABLE = 'addresses';

    public const INDEX = 'addr_account_default_unique';

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'default_marker')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unsignedTinyInteger('default_marker')->nullable();
            });
        }

        // Backfill BEFORE the unique index, so a half-finished earlier run can never trip it.
        if (DB::table(self::TABLE)->whereNotNull('default_marker')->doesntExist()) {
            DB::table(self::TABLE)->whereNotNull('account_id')->distinct()->orderBy('account_id')->pluck('account_id')->each(function ($accountId) {
                $id = DB::table(self::TABLE)->where('account_id', $accountId)->orderByDesc('updated_at')->orderByDesc('id')->value('id');

                DB::table(self::TABLE)->where('id', $id)->update(['default_marker' => 1]);
            });
        }

        if (! $this->indexExists()) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unique(['account_id', 'default_marker'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists()) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropUnique(self::INDEX);
            });
        }

        if (Schema::hasColumn(self::TABLE, 'default_marker')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropColumn('default_marker');
            });
        }
    }

    private function indexExists(): bool
    {
        return collect(Schema::getIndexes(self::TABLE))->contains(fn (array $index) => $index['name'] === self::INDEX);
    }
};
