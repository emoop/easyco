<?php

namespace App\Storefront\Cache;

/** P8b: the default everywhere until the cache layer exists (P8c): does nothing, and with it NO job is dispatched. */
final class NullPurger implements CachePurger
{
    public function purge(array $keys): void
    {
    }

    public function isActive(): bool
    {
        return false;
    }
}
