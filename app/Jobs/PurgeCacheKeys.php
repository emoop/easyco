<?php

namespace App\Jobs;

use App\Storefront\Cache\CachePurger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * P8b: one batch (at most 100 keys) of surrogate keys for the page cache. 3 tries with a back-off; a purge that still fails is logged, never thrown at a writer
 * (the write that caused it finished long ago). A persistent worker keeps the old code: after changing this class run `php artisan queue:restart` (CLAUDE.md).
 */
class PurgeCacheKeys implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @param list<string> $keys */
    public function __construct(public readonly array $keys)
    {
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30];
    }

    public function handle(CachePurger $purger): void
    {
        $purger->purge($this->keys);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('The page-cache purge failed for good.', ['keys' => count($this->keys), 'exception' => $exception::class, 'message' => $exception->getMessage()]);
    }
}
