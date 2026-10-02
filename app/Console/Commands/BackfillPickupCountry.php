<?php

namespace App\Console\Commands;

use App\Rules\KnownCountryCode;
use App\Settings\CountryNames;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Order\Enums\OrderDeliveryType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan addresses:backfill-pickup-country {code} [--dry-run] [--orders]`
 *
 * Shipping stage 3.0b (owner decision D1): every address and order carries a
 * delivery country, a pickup point included. Pickup-point rows saved before
 * that have none. THIS COMMAND IS HOW AN OPERATOR FILLS THEM — deliberately, by
 * choosing the code. Nothing runs it automatically, no migration, no seeder, no
 * request, and no business rule ever substitutes the store country for a
 * missing one.
 *
 * It fills ONLY rows that are PICKUP_POINT and have a NULL country. A row that
 * already has a country (right or wrong) and every street address are never
 * touched. `addresses` is always processed; `orders` only with --orders (an
 * order is a frozen fact — the operator must ask for that explicitly).
 *
 * The code is trimmed, uppercased and validated with KnownCountryCode (the
 * country list, XK included); an invalid one is refused before anything is
 * read or written. --dry-run prints exactly what would change and changes
 * nothing. A real run writes in one transaction and logs what it changed.
 *
 * The writes are plain UPDATEs, not Order::reviseDelivery(): that mutator is
 * for the merchant's edits (it bumps the edit revision, demands an editable
 * status and re-validates a whole snapshot), none of which is true of
 * correcting one missing fact on historical data.
 */
class BackfillPickupCountry extends Command
{
    protected $signature = 'addresses:backfill-pickup-country
                            {code : The ISO 3166-1 alpha-2 country code to set (e.g. BG)}
                            {--dry-run : Print what would change and change nothing}
                            {--orders : Also fill pickup-point ORDERS (otherwise only addresses)}';

    protected $description = 'Fill the missing country of pickup-point addresses (and, with --orders, orders) with a chosen code. Never automatic.';

    public function handle(): int
    {
        $code = KnownCountryCode::normalize((string) $this->argument('code'));
        $validator = validator(['code' => $code], ['code' => [new KnownCountryCode('delivery.country.invalid')]]);

        if ($validator->fails()) {
            $this->error('"'.$this->argument('code').'" is not a country code from the list ('.$validator->errors()->first('code').') Nothing was changed.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $targets = [['addresses', AddressDeliveryType::PICKUP_POINT->value]];

        if ($this->option('orders')) {
            $targets[] = ['orders', OrderDeliveryType::PICKUP_POINT->value];
        }

        $this->line(sprintf(
            '%s: country %s (%s) for pickup-point rows with no country.',
            $dryRun ? 'DRY RUN' : 'Backfill',
            $code,
            CountryNames::forLocale('en')[$code],
        ));

        $changes = [];

        DB::transaction(function () use ($targets, $code, $dryRun, &$changes): void {
            foreach ($targets as [$table, $pickupValue]) {
                $query = DB::table($table)->where('delivery_type', $pickupValue)->whereNull('country');
                $ids = (clone $query)->orderBy('id')->pluck('id')->all();
                $changes[$table] = $ids;

                if (! $dryRun && $ids !== []) {
                    $query->update(['country' => $code, 'updated_at' => now()]);
                }
            }
        });

        foreach ($changes as $table => $ids) {
            $this->line(sprintf(
                '  %s: %d pickup-point %s %s%s',
                $table,
                count($ids),
                count($ids) === 1 ? 'row' : 'rows',
                $dryRun ? 'would be filled' : 'filled',
                $ids === [] ? '' : ' (id: '.implode(', ', array_slice($ids, 0, 20)).(count($ids) > 20 ? ', …' : '').')',
            ));
        }

        if (! $this->option('orders')) {
            $this->line('  orders: not touched (pass --orders to include them).');
        }

        if (! $dryRun) {
            Log::info('addresses:backfill-pickup-country filled missing pickup-point countries', [
                'country' => $code,
                'rows' => $changes,
            ]);
        }

        return self::SUCCESS;
    }
}
