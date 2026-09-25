<?php

namespace App\Sandbox;

use App\Services\ProductPriceDisplay;
use App\Services\ProductPriceRangeProvider;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Assembles the sandbox list page's rows — Prompt D, D4.
 *
 * THE ONE PLACE THE PAGE'S PRICE WORK HAPPENS, AND IT HAPPENS ONCE:
 * ProductPriceRangeProvider::forProducts() is called exactly once with
 * the whole page's product ids, then each card reads its own already-
 * resolved PriceRange out of that one result. No callback, no view
 * helper, and no row can trigger a second resolve — which is what makes
 * D4's "the query count must not grow with the number of products on the
 * page" true by construction rather than by measurement alone
 * (SandboxProductListQueryCountTest measures it anyway, with real
 * numbers).
 *
 * IT DOES NOT RE-IMPLEMENT THE PRICE RULE (D6): rendering is delegated to
 * App\Services\ProductPriceDisplay::rangeHtml(), the same class and the
 * same method the admin product table's price column now goes through.
 * The list's price and the admin's price for the same product are the
 * same string because they are the same code, not because two
 * implementations were carefully kept in sync.
 *
 * THE PAGINATOR IS RETURNED AS-IS, with only its collection replaced —
 * currentPage/perPage/lastPage/total and the page links stay Laravel's
 * own, so pagination cannot drift from the actual query. D2's route
 * naming is used for the card urls (route('sandbox.products.show', ...)),
 * never a hand-built path string.
 */
final class SandboxProductListPage
{
    /**
     * $mediaDisk is injected, not read from config here — the same
     * "config is read only at the wiring boundary, never inside the
     * class body" rule MediaControllerServiceProvider already
     * establishes for MediaController, applied in SandboxServiceProvider
     * for this class ($mediaDisk = config('services.media.default_disk')).
     * Only the LIST needs it: the subquery selects a bare path, so there
     * is no MediaAsset object anywhere in this code path that could
     * report its own disk (the product PAGE's gallery does have the asset
     * and uses MediaAsset::disk() instead).
     */
    public function __construct(
        private readonly SandboxCatalogReader $reader,
        private readonly ProductPriceRangeProvider $priceRanges,
        private readonly ProductPriceDisplay $priceDisplay,
        private readonly MediaStorageAdapter $storage,
        private readonly string $mediaDisk,
    ) {
    }

    /**
     * @return LengthAwarePaginator<int, SandboxProductCard>
     */
    public function paginate(int $perPage = SandboxCatalogReader::PRODUCTS_PER_PAGE): LengthAwarePaginator
    {
        $products = $this->reader->paginateListedProducts($perPage);

        $productIds = $products->getCollection()
            ->map(static fn (ProductModel $product): string => (string) $product->id)
            ->all();

        $ranges = $this->priceRanges->forProducts($productIds);

        $products->setCollection($products->getCollection()->map(
            function (ProductModel $product) use ($ranges): SandboxProductCard {
                $productId = (string) $product->id;
                $thumbnailPath = $product->getAttribute('thumbnail_path');

                return new SandboxProductCard(
                    id: $productId,
                    name: (string) $product->name,
                    brandName: $product->brand?->name,
                    thumbnailUrl: $thumbnailPath === null
                        ? null
                        : $this->storage->url($this->mediaDisk, (string) $thumbnailPath),
                    priceHtml: $this->priceDisplay->rangeHtml($ranges[$productId] ?? null),
                    url: route('sandbox.products.show', ['productId' => $productId]),
                );
            }
        ));

        return $products;
    }
}
