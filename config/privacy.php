<?php

/**
 * ACC-4a: the privacy core (packages/EasyCo/documents/acc-4-gdpr-design.md).
 *
 * `hash_key` is the key of every keyed hash the privacy log and the suppression list store (HMAC-SHA256). It is NOT the APP_KEY: an APP_KEY
 * rotation must not make the suppression list unmatchable. Generate it once (`easyco-install` does), NEVER change it after go-live, and back it
 * up together with `.env`: a lost or changed key makes every existing suppression unmatchable.
 *
 * `clear_view_days`: an erase request keeps the person's email and phone in clear for exactly this long, then only the hash remains.
 * A constant on purpose (a legal posture, not a merchant setting).
 */
return [
    'hash_key' => env('PRIVACY_HASH_KEY'),

    'clear_view_days' => 30,

    // ACC-4d: POST /account/privacy/confirm (the identity-confirmation page), per IP and minute. A person presses one button once.
    'confirm_per_minute' => (int) env('RATE_LIMIT_PRIVACY_CONFIRM', 10),
];
