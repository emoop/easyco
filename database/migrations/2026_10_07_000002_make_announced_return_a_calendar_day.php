<?php

use App\Settings\StoreTimezone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Refunds R3 part 2, correction to part 1 (shipping-domain-design.md §7.2.6, §7.2.18): the date
     * the customer announced a return is a CALENDAR DAY in the store's timezone, not an instant —
     * the merchant types a date, nobody types hours, and a date turned into a midnight instant
     * shifts across the UTC boundary.
     *
     * `announced_return_at` (timestamp, UTC) becomes `announced_return_on` (DATE, nullable). The
     * CHECK is dropped first and re-added under the SAME name (MySQL/MariaDB only). The column
     * never held production data; any value that exists is converted to its STORE-timezone day
     * (not the UTC day an ALTER would truncate it to): the values are read before the type
     * changes and rewritten after.
     */
    public function up(): void
    {
        $values = DB::table('order_events')->whereNotNull('announced_return_at')->pluck('announced_return_at', 'id');
        $store = app(StoreTimezone::class);

        if ($this->supportsCheck()) {
            DB::statement('ALTER TABLE order_events DROP CONSTRAINT oe_announced_return_check');
        }

        Schema::table('order_events', function (Blueprint $table) {
            $table->renameColumn('announced_return_at', 'announced_return_on');
        });

        Schema::table('order_events', function (Blueprint $table) {
            $table->date('announced_return_on')->nullable()->change();
        });

        foreach ($values as $id => $instant) {
            DB::table('order_events')->where('id', $id)->update([
                'announced_return_on' => $store->dayOf(new DateTimeImmutable((string) $instant, new DateTimeZone('UTC'))),
            ]);
        }

        if ($this->supportsCheck()) {
            DB::statement("ALTER TABLE order_events ADD CONSTRAINT oe_announced_return_check CHECK (announced_return_on IS NULL OR type = 'returned')");
        }
    }

    /** Refuses while any event carries an announced day: turning a day back into an instant would invent a time. */
    public function down(): void
    {
        $used = DB::table('order_events')->whereNotNull('announced_return_on')->orderBy('id')->pluck('id');

        if ($used->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'order_events.id %s carries an announced-return day; rolling back would destroy it. Nothing was changed.',
                $used->take(20)->implode(', '),
            ));
        }

        if ($this->supportsCheck()) {
            DB::statement('ALTER TABLE order_events DROP CONSTRAINT oe_announced_return_check');
        }

        Schema::table('order_events', function (Blueprint $table) {
            $table->renameColumn('announced_return_on', 'announced_return_at');
        });

        Schema::table('order_events', function (Blueprint $table) {
            $table->timestamp('announced_return_at')->nullable()->change();
        });

        if ($this->supportsCheck()) {
            DB::statement("ALTER TABLE order_events ADD CONSTRAINT oe_announced_return_check CHECK (announced_return_at IS NULL OR type = 'returned')");
        }
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
