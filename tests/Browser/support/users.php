<?php

/*
 * Throwaway users for the local browser check (tests/Browser/checkout-pickup.spec.mjs). NEVER the owner's: the e-mails are
 * `pw-*@example.invalid`, the passwords are random and printed once as JSON for the script, and `delete` removes both users.
 *
 *   php tests/Browser/support/users.php create   -> {"staff":{"email","password"},"customer":{"email","password"}}
 *   php tests/Browser/support/users.php delete
 */

use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
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
    $customerPassword = bin2hex(random_bytes(12));

    $roles = app(RoleRepository::class);
    $role = $roles->findSystemRoleByName('Administrator');

    if ($role === null) {
        app(StaffSystemRolesSeeder::class)->run($roles);
        $role = $roles->findSystemRoleByName('Administrator');
    }

    $staff = Staff::create("pw-staff-{$tag}@example.invalid", app(PasswordHasher::class)->hash($staffPassword), 'Browser check', $role);
    app(StaffRepository::class)->save($staff);

    $account = Account::register("pw-customer-{$tag}@example.invalid", Hash::make($customerPassword));
    app(AccountRepository::class)->save($account);
    DB::table('accounts')->where('email', "pw-customer-{$tag}@example.invalid")->update(['email_verified_at' => now()]);

    $result = json_encode([
        'staff' => ['email' => "pw-staff-{$tag}@example.invalid", 'password' => $staffPassword],
        'customer' => ['email' => "pw-customer-{$tag}@example.invalid", 'password' => $customerPassword],
    ]);
    echo $result;
} catch (Throwable $e) {
    // a failure halfway must not leave a half-made user behind
    $delete();
    fwrite(STDERR, 'Creating the throwaway users failed (rolled back): '.get_class($e)."\n");
    exit(1);
}
