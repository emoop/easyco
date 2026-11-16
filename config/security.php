<?php

/*
| P8d: the app-side protection (packages/EasyCo/documents/security-p8d.md).
|
| `mode` is the ONE switch of every new BLOCKING rule of this package (admin login lockout, honeypots): `log` only logs what the rule WOULD have blocked,
| `block` blocks. Default `log`. "Safe mode" for recovery = set SECURITY_MODE=log again. The older limiters (filtered category / search limits, the API
| limiters, Filament's own login throttle) are NOT governed by it.
*/
return [
    'mode' => env('SECURITY_MODE', 'log'),

    'lockout' => [
        // failed credential submits per step, per (account + IP); the lock lasts `locks[step]` seconds: 5 min, then 30 min, then 24 h (and stays at 24 h)
        'failures_per_step' => 5,
        'locks' => [300, 1800, 86400],
        // the counter decays this long after the LAST failure
        'decay' => 86400,
    ],

    // the account-wide alarm (never blocks): this many failed submits for one account, from any IP, inside the window
    'alarm' => [
        'failures' => 20,
        'window' => 3600,
        'mail_every' => 3600,
    ],

    'honeypot' => [
        'field' => 'ec_hp',
    ],

    'trap' => [
        'path' => '/__ec/trap',
    ],

    'livewire_per_minute' => 300,

    'csp_report' => [
        'max_bytes' => 8192,
        'per_minute' => 30,
    ],
];
