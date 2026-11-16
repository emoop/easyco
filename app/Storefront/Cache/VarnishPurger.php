<?php

namespace App\Storefront\Cache;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** P8b: an HTTP `PURGE` to the INTERNAL cache address (config cache_purge.url, never a request value) with `xkey-softpurge: <keys>`. Any failure throws, so the queued job retries. */
final class VarnishPurger implements CachePurger
{
    public function __construct(private readonly string $url, private readonly int $timeout = 3)
    {
    }

    public function purge(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $response = Http::timeout($this->timeout)
            ->withHeaders(['xkey-softpurge' => implode(' ', $keys)])
            ->send('PURGE', $this->url);

        if (! $response->successful()) {
            throw new RuntimeException('The page-cache purge answered '.$response->status());
        }
    }

    public function isActive(): bool
    {
        return true;
    }
}
