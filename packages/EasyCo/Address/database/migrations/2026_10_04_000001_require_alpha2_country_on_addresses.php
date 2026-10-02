<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Shipping stage 3.0b (owner decision D1): the delivery country is an
     * uppercase ISO 3166-1 alpha-2 code on EVERY address, a pickup point
     * included. Address::create()/update() now refuse anything else.
     *
     * A GATE, NOT A GUESS (same posture as guard_no_fulfilled_orders): this
     * migration changes no schema. It only refuses to run while any stored
     * country is not exactly two uppercase ASCII letters, naming the rows,
     * because the domain would refuse to rebuild such an address for a new
     * order and nothing here can know what the right code is. Nothing is
     * written when it throws; correct the rows by hand and re-run.
     *
     * NULL is NOT an offender: a historical PICKUP_POINT address has none, and
     * inventing one (the store country, say) is exactly what D1 forbids. Those
     * rows are filled deliberately, by an operator, with
     * `php artisan addresses:backfill-pickup-country {code}`; nothing runs it
     * automatically. The column therefore stays nullable until none are left.
     *
     * The shape is tested in PHP, not with a REGEXP CHECK or WHERE: MySQL's
     * REGEXP follows the column collation (utf8mb4_unicode_ci), which is
     * case-INSENSITIVE, so `bg` would pass `^[A-Z]{2}$` — and a case-sensitive
     * form needs a collation clause whose behaviour could not be verified on
     * MariaDB. Reading the (id, country) pairs and testing in PHP is
     * unambiguous on every driver.
     */
    public function up(): void
    {
        $offenders = [];

        DB::table('addresses')
            ->whereNotNull('country')
            ->select(['id', 'country'])
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use (&$offenders): void {
                foreach ($rows as $row) {
                    if (preg_match('/^[A-Z]{2}$/D', (string) $row->country) !== 1) {
                        $offenders[] = sprintf('%s => "%s"', $row->id, $row->country);
                    }
                }
            });

        if ($offenders !== []) {
            throw new RuntimeException(sprintf(
                'addresses.country is not an uppercase two-letter code in %d %s (addresses.id => country: %s). '
                .'Nothing was changed; correct those rows by hand and re-run the migration.',
                count($offenders),
                count($offenders) === 1 ? 'row' : 'rows',
                implode(', ', array_slice($offenders, 0, 20)).(count($offenders) > 20 ? ', …' : ''),
            ));
        }
    }

    public function down(): void
    {
        // The gate changed nothing; there is nothing to undo.
    }
};
