<?php

namespace App\Services\PriceSort;

use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use Illuminate\Support\Facades\DB;

/**
 * P6a: the stock part of the fast path. A stock level is written by atomic increment / decrement (no Eloquent event), so this app-layer decorator of StockLevelRepository (registered in AppServiceProvider with
 * `extend()`; the Inventory package is untouched) queues a refresh of the variation's product whenever a stock change can change what is AVAILABLE:
 *
 *  - save() (the admin edit, an import): always;
 *  - decrease() (checkout): only when it takes the stock to zero or below, i.e. the variation just became unavailable;
 *  - increase() (a return, a restock): only when the stock was zero, i.e. the variation just became available.
 * decrease() / increase() read the quantity once before the write (one cheap primary-key query); a change that does not cross zero costs nothing else. The nightly pass repairs anything missed.
 */
final class StockRefreshingRepository implements StockLevelRepository
{
    public function __construct(
        private readonly StockLevelRepository $inner,
        private readonly SortPriceRefreshDispatcher $refresh,
    ) {
    }

    public function save(StockLevel $stockLevel): void
    {
        $this->inner->save($stockLevel);
        $this->refreshProductOf($stockLevel->variationId());
    }

    public function findByVariationId(string $variationId): StockLevel
    {
        return $this->inner->findByVariationId($variationId);
    }

    public function decrease(string $variationId, int $amount): void
    {
        $before = $this->inner->findByVariationId($variationId)->quantity();

        $this->inner->decrease($variationId, $amount);

        if ($before - $amount <= 0) {
            $this->refreshProductOf($variationId);
        }
    }

    public function increase(string $variationId, int $amount): void
    {
        $before = $this->inner->findByVariationId($variationId)->quantity();

        $this->inner->increase($variationId, $amount);

        if ($before <= 0) {
            $this->refreshProductOf($variationId);
        }
    }

    private function refreshProductOf(string $variationId): void
    {
        $productId = DB::table('catalog_variations')->where('id', $variationId)->value('product_id');

        $productId === null || $this->refresh->product($productId);
    }
}
