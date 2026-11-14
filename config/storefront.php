<?php

return [

    'search' => [
        // SRCH-1: storefront search (search.md). OFF by default: a shop that has not switched it on prints exactly the HTML it printed before and /search is the storefront's 404.
        // Switch on with STOREFRONT_SEARCH=true; the header's search element and the phone drawer's search row (its own switch in «Дизайн») then follow.
        'enabled' => filter_var(env('STOREFRONT_SEARCH', false), FILTER_VALIDATE_BOOLEAN),

        // The results page's rate limit per client IP (App\Http\Middleware\LimitSearchRequests): generous for people, tight for bots. P8 reviews the numbers.
        'limit' => (int) env('STOREFRONT_SEARCH_LIMIT', 30),
        'decay_seconds' => (int) env('STOREFRONT_SEARCH_DECAY', 60),
    ],
];
