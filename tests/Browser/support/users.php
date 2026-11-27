<?php

/*
 * Throwaway users for the local browser check (tests/Browser/checkout-pickup.spec.mjs). NEVER the owner's: the e-mails are
 * `pw-*@example.invalid`, the passwords are random and printed once as JSON for the script, and `delete` removes both users.
 *
 *   php tests/Browser/support/users.php create   -> {"staff":{...},"customer":{...},"saved":{...},"two":{...},"nopost":{...},"savedoffice":{...},"fixtures":{...}}
 *       staff        an Administrator of /admin
 *       customer     a customer WITHOUT a saved address
 *       saved        one saved street address (Харманли, 6450, Хасково)
 *       two          two saved street addresses (Харманли, and Стара Загора 6000), the first one the default
 *       nopost       one saved street address WITHOUT a postcode (the account's address form allows it)
 *       savedoffice  one saved street address plus one saved Speedy office address (COUR-2a.5, Step 0)
 *       odd          one saved street address whose city is only the START of a place name («Харман»): no confident match, the hint
 *       ambiguous_a  one saved street address in the FIRST of two same-named places (postcode and region of that one)
 *       ambiguous_b  the same for the SECOND place
 *       ambiguous_none  the same name with NO postcode and no region: two places, nothing to decide with
 *       fixtures     two settlements with the same name in different regions, both with a Speedy office (from the real data)
 *   php tests/Browser/support/users.php delete
 */

use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

chdir(dirname(__DIR__, 3));
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// LOCAL MACHINES ONLY (create AND delete): this writes users into whatever database the app is configured for.
$host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

if (! app()->environment('local') || ! ($host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.test'))) {
    fwrite(STDERR, "Refused: tests/Browser/support/users.php runs only when APP_ENV=local and APP_URL is a .test host, localhost or 127.0.0.1 (now: env=".app()->environment().", host={$host}).\n");
    exit(1);
}

$mode = $argv[1] ?? '';

$delete = static function (): void {
    $staffIds = DB::table('staff')->where('email', 'like', 'pw-%@example.invalid')->pluck('id');
    DB::table('staff')->whereIn('id', $staffIds)->delete();
    DB::table('accounts')->where('email', 'like', 'pw-%@example.invalid')->delete();
};

if ($mode === 'delete') {
    $delete();
    echo json_encode(['deleted' => true]);
    exit(0);
}

if ($mode !== 'create') {
    fwrite(STDERR, "usage: users.php create|delete\n");
    exit(1);
}

try {
    $tag = bin2hex(random_bytes(4));
    $staffPassword = bin2hex(random_bytes(12));

    $roles = app(RoleRepository::class);
    $role = $roles->findSystemRoleByName('Administrator');

    if ($role === null) {
        app(StaffSystemRolesSeeder::class)->run($roles);
        $role = $roles->findSystemRoleByName('Administrator');
    }

    $staff = Staff::create("pw-staff-{$tag}@example.invalid", app(PasswordHasher::class)->hash($staffPassword), 'Browser check', $role);
    app(StaffRepository::class)->save($staff);

    $makeCustomer = static function (string $name) use ($tag): array {
        $email = "pw-{$name}-{$tag}@example.invalid";
        $password = bin2hex(random_bytes(12));
        $account = Account::register($email, Hash::make($password));
        app(AccountRepository::class)->save($account);
        DB::table('accounts')->where('email', $email)->update(['email_verified_at' => now()]);

        return ['email' => $email, 'password' => $password, 'account_id' => (string) DB::table('accounts')->where('email', $email)->value('id')];
    };

    $street = static function (string $accountId, string $city, ?string $postcode, ?string $region, string $line): Address {
        return Address::create(
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            recipientFirstName: 'Тест',
            recipientLastName: 'Браузър',
            phone: '+359888123456',
            accountId: $accountId,
            country: 'BG',
            city: $city,
            postalCode: $postcode,
            addressLine1: $line,
            region: $region,
        );
    };

    $addresses = app(AddressRepository::class);
    $saveAll = static function (array $customer, array $rows) use ($addresses): void {
        foreach ($rows as $index => $address) {
            $addresses->save($address);

            if ($index === 0) {
                $addresses->setDefault((string) $address->id(), $customer['account_id']);
            }
        }
    };

    $customer = $makeCustomer('customer');
    $saved = $makeCustomer('saved');
    $two = $makeCustomer('two');
    $nopost = $makeCustomer('nopost');
    $savedoffice = $makeCustomer('office');
    $odd = $makeCustomer('odd');
    $ambiguousA = $makeCustomer('ambia');
    $ambiguousB = $makeCustomer('ambib');
    $ambiguousNone = $makeCustomer('ambin');

    $saveAll($saved, [$street($saved['account_id'], 'Харманли', '6450', 'Хасково', 'ул. Тестова 1')]);
    $saveAll($two, [
        $street($two['account_id'], 'Харманли', '6450', 'Хасково', 'ул. Тестова 1'),
        $street($two['account_id'], 'Стара Загора', '6000', 'Стара Загора', 'ул. Тестова 2'),
    ]);
    $saveAll($nopost, [$street($nopost['account_id'], 'Харманли', null, null, 'ул. Тестова 1')]);
    $saveAll($savedoffice, [
        $street($savedoffice['account_id'], 'Харманли', '6450', 'Хасково', 'ул. Тестова 1'),
        Address::create(
            deliveryType: AddressDeliveryType::PICKUP_POINT,
            recipientFirstName: 'Тест',
            recipientLastName: 'Браузър',
            phone: '+359888123456',
            accountId: $savedoffice['account_id'],
            country: 'BG',
            carrierCode: 'Спиди', // what the page's free-text path stores: the courier's display name (selection.carrier)
            settlement: 'Харманли',
            pickupPointAddress: 'Тестов офис на Спиди',
            pickupPointKind: 'office',
        ),
    ]);

    $saveAll($odd, [$street($odd['account_id'], 'Харман', '6450', 'Хасково', 'ул. Тестова 1')]);

    // two settlements with the same name in different regions, both with a Speedy office: the postcode (and the region) tell them apart
    $pair = DB::table('shipping_settlements as s')
        ->join('shipping_pickup_points as p', function ($join) {
            $join->on('p.settlement_id', '=', 's.id')->where('p.carrier_code', 'speedy')->where('p.kind', 'office');
        })
        ->select('s.name_key', 's.id', 's.name', 's.region_code', 's.postcodes')
        ->distinct()->get()->groupBy('name_key')
        ->first(fn ($rows) => $rows->count() === 2 && $rows->every(fn ($r) => count(json_decode((string) $r->postcodes, true) ?: []) === 1) && $rows->pluck('postcodes')->unique()->count() === 2);

    if ($pair !== null) {
        $places = $pair->values()->all();
        $regionName = static fn (object $row): string => (string) \App\Support\BgRegions::name((string) $row->region_code);
        $saveAll($ambiguousA, [$street($ambiguousA['account_id'], $places[0]->name, json_decode((string) $places[0]->postcodes, true)[0], $regionName($places[0]), 'ул. Тестова 1')]);
        $saveAll($ambiguousB, [$street($ambiguousB['account_id'], $places[1]->name, json_decode((string) $places[1]->postcodes, true)[0], $regionName($places[1]), 'ул. Тестова 1')]);
        $saveAll($ambiguousNone, [$street($ambiguousNone['account_id'], $places[0]->name, null, null, 'ул. Тестова 1')]);
    }

    $fixtures = ['ambiguous' => $pair === null ? null : $pair->map(fn ($r) => ['id' => (string) $r->id, 'name' => $r->name, 'region_code' => $r->region_code, 'postcode' => json_decode((string) $r->postcodes, true)[0]])->values()->all()];

    $result = json_encode([
        'staff' => ['email' => "pw-staff-{$tag}@example.invalid", 'password' => $staffPassword],
        'customer' => ['email' => $customer['email'], 'password' => $customer['password']],
        'saved' => ['email' => $saved['email'], 'password' => $saved['password']],
        'two' => ['email' => $two['email'], 'password' => $two['password']],
        'nopost' => ['email' => $nopost['email'], 'password' => $nopost['password']],
        'savedoffice' => ['email' => $savedoffice['email'], 'password' => $savedoffice['password']],
        'odd' => ['email' => $odd['email'], 'password' => $odd['password']],
        'ambiguous_a' => ['email' => $ambiguousA['email'], 'password' => $ambiguousA['password']],
        'ambiguous_b' => ['email' => $ambiguousB['email'], 'password' => $ambiguousB['password']],
        'ambiguous_none' => ['email' => $ambiguousNone['email'], 'password' => $ambiguousNone['password']],
        'fixtures' => $fixtures,
    ], JSON_UNESCAPED_UNICODE);
    echo $result;
} catch (Throwable $e) {
    // a failure halfway must not leave a half-made user behind
    $delete();
    fwrite(STDERR, 'Creating the throwaway users failed (rolled back): '.get_class($e)."\n");
    exit(1);
}
