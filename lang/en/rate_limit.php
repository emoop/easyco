<?php

/*
 * The refusal of the named API rate limiters that are not about one domain's own
 * work (input hardening pass 3) — registration, checkout, address writes and
 * promo-code attempts. Unlike shipping_quote.too_many_requests these four answer
 * with ONE shared sentence: the endpoints are the storefront's public write
 * surface, and a refusal that says which one was hit tells an abuser which budget
 * to spend next. The shipping quote keeps its own, more helpful wording — it is a
 * price enquiry a shopper repeats legitimately.
 *
 * Translation keys, not sentences: see App\Http\ApiRateLimits, the one place the
 * limiters are defined, and its own docblock for the wording of the convention.
 */
return [
    'too_many_requests' => 'Too many requests. Wait a moment and try again.',
];
