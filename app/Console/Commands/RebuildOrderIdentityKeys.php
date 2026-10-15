<?php

namespace App\Console\Commands;

use App\Services\PhoneNumbers;
use EasyCo\Order\IdentityKeys;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan orders:rebuild-identity-keys` — recomputes the four identity keys (phone_key, email_key, name_key, place_key) of EVERY order from
 * the contact and delivery facts the row holds, with the one normaliser (EasyCo\Order\IdentityKeys; the phone through PhoneNumbers: E.164 when the number parses with the order's country). The keys are derived data
 * (cod-protection-design.md section 2): when a rule of the normaliser changes, they are rebuilt with this command, never edited by hand.
 *
 * IDEMPOTENT: a row whose keys already equal what the rules give is not written, so a second run changes nothing and says "0 changed".
 * It touches only the four key columns. It reports COUNTS, never a value (no phone, email, name or address is printed or logged).
 */
class RebuildOrderIdentityKeys extends Command
{
    protected $signature = 'orders:rebuild-identity-keys';

    protected $description = 'Recompute the derived identity keys (phone, email, name, delivery place) of every order; counts only';

    public function handle(): int
    {
        $seen = 0;
        $changed = 0;

        DB::table('orders')
            ->orderBy('id')
            ->select(['id', 'email', 'phone', 'recipient_first_name', 'recipient_last_name', 'delivery_type', 'country', 'city', 'address_line_1', 'carrier_code', 'pickup_point_address', 'phone_key', 'email_key', 'name_key', 'place_key'])
            ->chunkById(500, function ($rows) use (&$seen, &$changed): void {
                foreach ($rows as $row) {
                    $seen++;
                    $keys = IdentityKeys::forRow($row);
                    // A phone that parses with the order's own country is keyed by its E.164 form, whatever way it was stored (stage PH-1).
                    $keys['phone_key'] = PhoneNumbers::identityKey($row->phone, $row->country);

                    if ($keys !== ['phone_key' => $row->phone_key, 'email_key' => $row->email_key, 'name_key' => $row->name_key, 'place_key' => $row->place_key]) {
                        DB::table('orders')->where('id', $row->id)->update($keys);
                        $changed++;
                    }
                }
            });

        if ($changed > 0) {
            Log::info('orders.identity_keys_rebuilt', ['orders' => $seen, 'changed' => $changed]);
        }

        $this->info("Checked {$seen} order(s); rebuilt the identity keys of {$changed}.");

        return self::SUCCESS;
    }
}
