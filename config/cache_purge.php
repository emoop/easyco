<?php

/*
 * P8b: the page-cache purge (packages/EasyCo/documents/storefront-cache-contract.md). `null` until the cache layer exists (P8c switches the Docker
 * stack to `varnish`). `url` is the INTERNAL address of the cache (the Docker service), never reachable from the internet.
 */
return [
    'purger' => env('CACHE_PURGER', 'null'),
    'url' => env('CACHE_PURGE_URL', 'http://varnish'),
    'timeout' => 3,
    'batch' => 100,
];
