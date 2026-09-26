<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Cart-side half of the after-checkout fix — cart-domain-design.md §14.1/§14.5.
 *
 * A claimed cart must stop being the customer's current cart, so the claim (the
 * same single conditional UPDATE on order_id, checkout-domain-design.md §6) also
 * MOVES the identity: account_id/session_token are copied here and NULLed on the
 * live columns. The live columns keep their unique() indexes, which now mean
 * exactly what they should always have meant — at most one LIVE cart per identity.
 *
 * WHY THE IDENTITY MOVES RATHER THAN A FILTER BEING ADDED: every current-cart
 * lookup (findByAccountId()/findBySessionToken()/findById()) is correct by
 * construction afterwards, because a claimed row no longer carries an identity any
 * lookup asks about. There is no `order_id IS NULL` filter for a future caller,
 * join or report to forget — see §14.1 for the alternatives rejected, including
 * the generated-column/partial-uniqueness emulation MySQL would need.
 *
 * claimed_account_id keeps the "a cart does not outlive its account" rule
 * (§10) for a claimed row — for such a row this FK is now the only one that can
 * carry it. claimed_session_token is deliberately NOT unique: several claimed
 * carts may legitimately share one token's history, and a guest's next cart
 * reuses the very same token (the live unique index is free again) — §14.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->foreignId('claimed_account_id')->nullable()->after('account_id')
                ->constrained('accounts', indexName: 'carts_claimed_account_id_foreign')
                ->cascadeOnDelete();
            $table->string('claimed_session_token')->nullable()->index()->after('session_token');
        });

        // Already-claimed rows (the dev database included) move their identity, so
        // the fix applies to history and not only to carts claimed from now on.
        DB::table('carts')->whereNotNull('order_id')->update([
            'claimed_account_id' => DB::raw('account_id'),
            'claimed_session_token' => DB::raw('session_token'),
            'account_id' => null,
            'session_token' => null,
        ]);
    }

    /**
     * A PARTIAL INVERSE, ON PURPOSE — §14.5: live identity is restored only where a
     * LIVE cart has not since taken that identity (after this change one identity
     * legitimately holds one claimed AND one live cart), because a blind restore
     * would fail the unique indexes. A row that cannot be restored keeps its
     * claimed values until the columns are dropped; "reversible" must not mean
     * "reversible after deleting the customer's new cart".
     */
    public function down(): void
    {
        $claimedRows = DB::table('carts')->whereNotNull('order_id')
            ->get(['id', 'claimed_account_id', 'claimed_session_token']);

        foreach ($claimedRows as $row) {
            $identityTakenByALiveCart = DB::table('carts')
                ->where('id', '!=', $row->id)
                ->where(function ($query) use ($row): void {
                    if ($row->claimed_account_id !== null) {
                        $query->orWhere('account_id', $row->claimed_account_id);
                    }

                    if ($row->claimed_session_token !== null) {
                        $query->orWhere('session_token', $row->claimed_session_token);
                    }
                })
                ->exists();

            if ($identityTakenByALiveCart) {
                continue;
            }

            DB::table('carts')->where('id', $row->id)->update([
                'account_id' => $row->claimed_account_id,
                'session_token' => $row->claimed_session_token,
            ]);
        }

        // FK before the column that supports it — same ordering lesson the
        // add_order_id migration on this table already documents.
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropForeign('carts_claimed_account_id_foreign');
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->dropColumn(['claimed_account_id', 'claimed_session_token']);
        });
    }
};
