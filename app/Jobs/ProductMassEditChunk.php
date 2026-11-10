<?php

namespace App\Jobs;

use App\Services\MassEdit\ProductMassEditor;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use Filament\Facades\Filament;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * P6d: ONE chunk (at most ProductMassEditor::CHUNK products) of a queued mass edit. It runs ProductMassEditor::applyChunk(), the same code the synchronous path runs; every operation is idempotent, so a retry
 * is harmless. The audit entries carry the staff member who started the edit (the job puts them in the panel guard for its own run), exactly as if they had saved the products by hand. The panel has no database
 * notifications, so the outcome of every chunk is a LOG line (the Filament layer tells the staff member how many products were accepted).
 *
 * A persistent queue worker boots the app once: after changing this class run `php artisan queue:restart` (CLAUDE.md).
 */
class ProductMassEditChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** @param list<string> $productIds */
    public function __construct(
        public readonly string $operation,
        public readonly ?string $value,
        public readonly array $productIds,
        public readonly ?string $actorStaffId = null,
    ) {
    }

    public function handle(ProductMassEditor $editor): void
    {
        $guard = Filament::auth();
        $previous = $guard->user();
        $actor = $this->actorStaffId === null ? null : StaffModel::query()->find($this->actorStaffId);
        $actor === null || $guard->setUser($actor);

        try {
            $result = $editor->applyChunk($this->operation, $this->value, $this->productIds);

            Log::info('Product mass edit chunk done.', ['operation' => $this->operation, 'value' => $this->value, 'products' => count($this->productIds), 'changed' => $result->changed, 'unchanged' => $result->unchanged, 'skipped' => $result->skipped, 'removed_prices' => $result->removedPrices, 'staff' => $this->actorStaffId]);
        } catch (Throwable $exception) {
            Log::error('Product mass edit chunk failed.', ['operation' => $this->operation, 'products' => count($this->productIds), 'exception' => $exception->getMessage()]);

            throw $exception;
        } finally {
            $previous === null ? null : $guard->setUser($previous);
        }
    }
}
