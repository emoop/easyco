<?php

namespace App\Jobs;

use App\Enums\CatalogLookupKind;
use App\Services\DetachProductFromCatalogLookup;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs one chunk (up to 50 products — admin-panel-design.md §7's
 * threshold) of a bulk-unlink operation, queued rather than
 * synchronous — mirrors ProcessMediaAssetJob's existing queue-worker
 * infrastructure, not a new one. Dispatched in groups via Bus::batch()
 * by each drill-down page's bulk-unlink action whenever the selection
 * exceeds 50; a selection of 50 or fewer never reaches this class at
 * all and is processed inline in the same request instead.
 */
class DetachProductFromCatalogLookupJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @param string[] $productIds up to 50 */
    public function __construct(
        private readonly CatalogLookupKind $kind,
        private readonly array $productIds,
        private readonly ?string $entityId,
    ) {
    }

    public function handle(DetachProductFromCatalogLookup $detacher): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        foreach ($this->productIds as $productId) {
            $detacher->detach($this->kind, $productId, $this->entityId);
        }
    }
}
