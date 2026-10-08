<?php

/*
 * Requests per minute of the named API rate limiters (App\Http\ApiRateLimits, the
 * ONE place they are defined). Overridable per environment; a value that is not a
 * positive integer falls back to the default in code.
 *
 * EVERY named limiter must have a row here — a test asserts it (the code's own
 * fallback, 30/min, is a safety net for a damaged config file, never a budget
 * anyone should rely on). The four added in the third input-hardening pass are
 * ceilings on abuse, not rates a person needs: registering is one POST per new
 * account, placing an order is one POST per order, and an address write or a
 * promo-code attempt is a one-off per shopper.
 */
return [
    'shipping-quote' => (int) env('RATE_LIMIT_SHIPPING_QUOTE', 30),

    // Creating accounts: five a minute from one IP is already a scripted
    // registration campaign; a human needs one.
    'registration' => (int) env('RATE_LIMIT_REGISTRATION', 5),

    // Placing an order (guest or signed in): the most expensive write the
    // storefront has, and a shopper places one — a session's retries and
    // failed card attempts stay well under ten.
    'checkout' => (int) env('RATE_LIMIT_CHECKOUT', 10),

    // Address writes (the address a guest checks out with, a customer's own
    // saved address): bursty but harmless, so the loosest of the four — still
    // capped, because it is an authenticated-less POST that writes rows.
    'address' => (int) env('RATE_LIMIT_ADDRESS', 20),

    // Applying a promo code — a secret the caller is guessing. Ten a minute
    // leaves room for typos and caps guessing at 600 an hour per IP.
    'promotion' => (int) env('RATE_LIMIT_PROMOTION', 10),
];

