<?php

namespace App\Storefront\Cache;

/** P8b: sends a batch of surrogate keys to the page cache (a SOFT purge: the cache may serve the stale page while it fetches a fresh one). Throws when the purge failed (the queued job retries). */
interface CachePurger
{
    /** @param list<string> $keys */
    public function purge(array $keys): void;

    /** Does this purger do anything? NullPurger says no: nothing is queued and no subject key is resolved. */
    public function isActive(): bool;
}
