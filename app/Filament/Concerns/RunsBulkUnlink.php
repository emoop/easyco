<?php

namespace App\Filament\Concerns;

use App\Enums\CatalogLookupKind;
use App\Jobs\DetachProductFromCatalogLookupJob;
use App\Services\DetachProductFromCatalogLookup;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

/**
 * The single call site every drill-down page's bulk-unlink action
 * uses — admin-panel-design.md §7's threshold: a selection of 50 or
 * fewer processes synchronously, inline, in the same request (a real
 * "N of M actually detached" count is known immediately); a selection
 * above 50 is split into chunks of 50 and dispatched as
 * DetachProductFromCatalogLookupJob instances inside one Bus::batch()
 * (Laravel's own batch-job primitive, mirroring the existing
 * ProcessMediaAssetJob queue-worker infrastructure) — for that path
 * the exact final count isn't known synchronously (the jobs may still
 * be running after this request returns), so the notification says
 * how many were queued, not how many were detached.
 */
trait RunsBulkUnlink
{
    protected function runBulkUnlink(Collection $productIds, CatalogLookupKind $kind, ?string $entityId): void
    {
        $productIds = $productIds->map(fn ($id): string => (string) $id)->values();

        if ($productIds->count() <= 50) {
            $detacher = app(DetachProductFromCatalogLookup::class);
            $detachedCount = 0;

            foreach ($productIds as $productId) {
                if ($detacher->detach($kind, $productId, $entityId)) {
                    $detachedCount++;
                }
            }

            Notification::make()
                ->title(trans_choice('related_products.bulk_unlink.detached', $detachedCount, [
                    'count' => $detachedCount,
                    'total' => $productIds->count(),
                ]))
                ->success()
                ->send();

            return;
        }

        $jobs = $productIds
            ->chunk(50)
            ->map(fn (Collection $chunk): DetachProductFromCatalogLookupJob => new DetachProductFromCatalogLookupJob($kind, $chunk->all(), $entityId))
            ->all();

        Bus::batch($jobs)
            ->name("detach-{$kind->value}-{$entityId}")
            ->dispatch();

        Notification::make()
            ->title(trans('related_products.bulk_unlink.queued', ['count' => $productIds->count()]))
            ->success()
            ->send();
    }
}
