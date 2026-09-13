<?php

use App\Filament\StaffPanelUser;
use App\Models\User;
use EasyCo\Account\Persistence\Eloquent\AccountModel;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        // Storefront customer login (account-domain-design.md §2) —
        // deliberately separate from 'web'/'users' above. Also
        // deliberately separate from 'staff' below, per
        // staff-access-domain-design.md §2: a customer session must
        // never double as a staff session, and vice versa — a staff
        // member must not be discoverable, enumerable or
        // password-resettable through the storefront's own
        // customer-facing endpoints, and a customer must never be able
        // to reach the merchant surface via their own session. Session-
        // driven, backed by the 'accounts' provider below.
        'customer' => [
            'driver' => 'session',
            'provider' => 'accounts',
        ],

        // Merchant-surface login (staff-access-domain-design.md §2) —
        // the "possible future staff/admin login" the 'customer' guard's
        // own comment used to anticipate; that future has now arrived.
        // Deliberately separate from both 'web' and 'customer' above,
        // for the same reasoning stated on 'customer': a staff session
        // must never double as anything else. Session-driven, backed by
        // the 'staff' provider below.
        'staff' => [
            'driver' => 'session',
            'provider' => 'staff',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],

        // Backs the 'customer' guard above — storefront customers, a
        // separate table/model from 'users' (account-domain-design.md §2).
        'accounts' => [
            'driver' => 'eloquent',
            'model' => AccountModel::class,
        ],

        // Backs the 'staff' guard above — merchant-surface identities, a
        // separate table/model from both 'users' and 'accounts'
        // (staff-access-domain-design.md §2). Points at
        // App\Filament\StaffPanelUser (a thin subclass of the package's
        // own StaffModel) rather than the package model directly, purely
        // so the resolved user implements Filament\Models\Contracts\
        // FilamentUser — see that class's own docblock for why this is
        // required, not optional (admin-panel-design.md §3).
        'staff' => [
            'driver' => 'eloquent',
            'model' => StaffPanelUser::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
