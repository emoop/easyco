<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Refunds R3 part 1 (shipping-domain-design.md §7.2.6, owner decision Q3): the date the
     * customer ANNOUNCED the return lives on the return operation's history row — the
     * `returned` event — not on the refund, because a return that produces no refund still
     * has one.
     *
     * A COLUMN, not the event's payload: `order_events` has no payload column, and the order
     * page already reads the whole history in one `select *`, so a column arrives with the
     * rows it already reads — no extra query, no join. Stored in UTC like occurred_at.
     *
     * CHECK (MySQL/MariaDB only, same supportsCheck() pattern as the operation columns): the
     * announced date is set only on a `returned` event (CLAUDE.md rule 2). Name explicit and
     * short (rule 5).
     */
    public function up(): void
    {
        Schema::table('order_events', function (Blueprint $table) {
            $table->timestamp('announced_return_at')->nullable()->after('occurred_at');
        });

        if ($this->supportsCheck()) {
            DB::statement("ALTER TABLE order_events ADD CONSTRAINT oe_announced_return_check CHECK (announced_return_at IS NULL OR type = 'returned')");
        }
    }

    /** Refuses while any event carries an announced date: dropping the column would destroy that record. */
    public function down(): void
    {
        $used = DB::table('order_events')->whereNotNull('announced_return_at')->orderBy('id')->pluck('id');

        if ($used->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'order_events.id %s carries an announced-return date; rolling back would destroy it. Nothing was changed.',
                $used->take(20)->implode(', '),
            ));
        }

        if ($this->supportsCheck()) {
            DB::statement('ALTER TABLE order_events DROP CONSTRAINT oe_announced_return_check');
        }

        Schema::table('order_events', function (Blueprint $table) {
            $table->dropColumn('announced_return_at');
        });
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
