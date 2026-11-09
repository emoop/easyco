<?php

namespace App\Jobs;

use App\Services\PriceSort\ProductSortPriceRefresher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * P6a: refreshes the stored sort price (`catalog_products.price_from_minor`) of up to ProductSortPriceRefresher::CHUNK products.
 *
 * UNIQUE PER SET OF PRODUCTS, UNTIL IT STARTS: many changes of the same product in a row (a save writes the product, its variations, its tags and its prices) are ONE queued job; the lock is released when the job
 * STARTS processing (ShouldBeUniqueUntilProcessing), so a change that arrives while it runs queues a fresh one and the last state is always read. Queued AFTER COMMIT, so the worker never reads the state before the write.
 *
 * A persistent queue worker boots the app once: after changing this class run `php artisan queue:restart` (CLAUDE.md).
 */
class RefreshProductSortPrices implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** The unique lock is dropped after an hour even if the job never started (a stopped queue must not block later changes for ever). */
    public int $uniqueFor = 3600;

    /** @param list<string> $productIds */
    public function __construct(public readonly array $productIds)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        $ids = array_values(array_unique(array_map('strval', $this->productIds)));
        sort($ids);

        return count($ids) === 1 ? 'p'.$ids[0] : 'set-'.md5(implode(',', $ids));
    }

    public function handle(ProductSortPriceRefresher $refresher): void
    {
        $refresher->refresh($this->productIds);
    }
}
