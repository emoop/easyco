<?php

/*
 * Requests per minute of the named API rate limiters (App\Http\ApiRateLimits, the
 * ONE place they are defined). Overridable per environment; a value that is not a
 * positive integer falls back to the default in code.
 */
return [
    'shipping-quote' => (int) env('RATE_LIMIT_SHIPPING_QUOTE', 30),
];
