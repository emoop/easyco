<?php

namespace App\Console\Commands;

use App\Services\PriceSort\ProductSortPriceRefresher;
use Illuminate\Console\Command;

/**
 * P6a, the SAFETY NET: recomputes the stored sort price (`catalog_products.price_from_minor`) of EVERY product, in chunks of about 200 with the batched resolvers (a fixed number of grouped reads per chunk, never a
 * query per product), writes only the rows whose value changed and is idempotent (a second run writes nothing). Scheduled once a night at 03:30 shop time (routes/console.php) and runnable by hand: it is
 * also the step that fills the column after the migration. It repairs whatever the fast path and the time boundaries missed (an import, a direct database change, a stopped queue), so the stored value is never
 * wrong for longer than a day.
 */
class RefreshProductSortPricesFromAll extends Command
{
    protected $signature = 'prices:refresh-from';

    protected $description = 'Recompute the stored sort price (price_from_minor) of every product, in chunks, writing only what changed.';

    public function handle(ProductSortPriceRefresher $refresher): int
    {
        $result = $refresher->refreshAll(function (int $products, int $written): void {
            $this->components->twoColumnDetail("a chunk of {$products} products", $written === 0 ? 'unchanged' : "{$written} rewritten");
        });

        $this->info("Done: {$result['products']} products, {$result['written']} rewritten.");

        return self::SUCCESS;
    }
}
