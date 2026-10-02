<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Shipping stage 3.0b (owner decision D1): the delivery country is an
     * uppercase ISO 3166-1 alpha-2 code on EVERY order, a pickup point
     * included. Order::create()/reviseDelivery() now refuse anything else.
     *
     * A GATE, NOT A GUESS — see 2026_10_04_000001_require_alpha2_country_on_addresses
     * (the same reasoning, the same shape): no schema change, it only refuses to
     * run while any stored country is not exactly two uppercase ASCII letters,
     * naming the rows. NULL is not an offender: orders are immutable history,
     * and a historical pickup-point order with no country still loads (the
     * entity's read path adds no check). `addresses:backfill-pickup-country
     * {code} --orders` fills them only when an operator chooses to. The column
     * stays nullable for that reason.
     */
    public function up(): void
    {
        $offenders = [];

        DB::table('orders')
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
                'orders.country is not an uppercase two-letter code in %d %s (orders.id => country: %s). '
                .'Nothing was changed; correct those orders by hand and re-run the migration.',
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
