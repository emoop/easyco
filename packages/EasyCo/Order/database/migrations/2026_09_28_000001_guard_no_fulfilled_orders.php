<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A GATE, NOT A TRANSFORMATION — order-lifecycle-design.md §10 stage 1,
     * owner decision D1.
     *
     * WHY IT EXISTS: the case it guards was retired from
     * EasyCo\Order\Enums\OrderStatus in the same change, because it was one
     * word for two facts a merchant's fulfilment queue has to tell apart
     * ("prepared, still in my shop" and "gone, in someone else's hands").
     * `orders.status` is a plain string column with no native DB enum (the
     * creating migration's own deliberate choice), so removing the case leaves
     * every row that already holds the value readable but no longer writable
     * by any code in the project — and nothing else would ever repair it.
     * This migration is what makes that state loud instead of silent.
     *
     * WHY IT MAPS NOTHING: rewriting those rows to `shipped` would be the one
     * irreversible statement in the whole build order, and it would be a guess
     * — the retired value meant "prepared OR handed over", and no column in
     * the row says whether the parcel ever left the merchant's hands. Those
     * are real orders and a decision about each of them, not a mapping. So
     * up() counts the rows that still hold it and, when there are any, throws
     * an exception naming the count and the `orders.id` values, changing
     * nothing at all. A deployment that holds them resolves each order by hand
     * (order-lifecycle-design.md §1/§2.1) and re-runs the migration; a
     * deployment that holds none passes straight through, with the proof
     * recorded in its own `migrations` table.
     *
     * WHAT IT TOUCHES: one SELECT and no writes, ever. No index and no foreign
     * key is created, so the explicit short-name convention every schema
     * migration in this project follows (MySQL/MariaDB's 64-character
     * identifier limit, CLAUDE.md rule 5) does not apply to this file: there is
     * deliberately no `ord_...` name to argue about.
     *
     * down() IS A NO-OP, and can be nothing else: up() writes nothing, so there
     * is nothing to undo — and a down() that "restored" a retired value into a
     * deployment's data would be a second guess at the same question.
     */
    private const RETIRED_STATUS = 'fulfilled';

    public function up(): void
    {
        $ids = DB::table('orders')
            ->where('status', self::RETIRED_STATUS)
            ->orderBy('id')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        throw new RuntimeException(sprintf(
            "orders.status still holds '%s' in %d %s (orders.id: %s). That status was retired — "
            .'it was one word for two different facts ("prepared, still in my shop" and "gone, in '
            ."someone else's hands\"), and nothing in these rows says which one they meant, so this "
            .'migration rewrites nothing. Decide each order\'s real status by hand '
            .'(order-lifecycle-design.md §1 and §2.1), then re-run it.',
            self::RETIRED_STATUS,
            $ids->count(),
            $ids->count() === 1 ? 'row' : 'rows',
            $ids->implode(', '),
        ));
    }

    public function down(): void
    {
        // Nothing to undo: up() reads and refuses, and never writes.
    }
};
