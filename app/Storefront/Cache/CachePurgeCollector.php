<?php

namespace App\Storefront\Cache;

use App\Jobs\PurgeCacheKeys;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * P8b: THE one door of the page-cache purge. Writers and listeners only COLLECT (`add*`): nothing is sent from there. The collector flushes ONCE per HTTP
 * request / artisan command (`terminating`) and ONCE per queued job (JobProcessed / JobFailed, wired in CachePurgeListeners), de-duplicates, and sends the
 * keys in batches of at most `cache_purge.batch` (100) through the queued job PurgeCacheKeys and the CachePurger contract.
 *
 * With the NullPurger (the default) NOTHING is queued and NO query is made: the keys that are known at once (`sitemap`, `all`, `home`, `p{id}`) are still
 * counted so the Laravel-side version keys (CacheVersion) move; the subject keys of a product (its categories with their ancestors, brand, season, tags,
 * collections) are resolved from the database at flush, in a fixed number of grouped statements, only for a purger that does something.
 *
 * A failing purge or a failing resolution is logged and NEVER breaks the write (the write already finished). Collecting inside a transaction that later
 * rolls back only causes an extra purge: accepted.
 */
#[Scoped]
final class CachePurgeCollector
{
    /** @var array<string, true> */
    private array $keys = [];

    /** @var array<string, true> product ids whose product rule applies */
    private array $products = [];

    /** @var array<string, true> variation ids: their product gets `p{id}` only (a stock change that does not cross zero, a price row) */
    private array $variations = [];

    /** @var array<string, true> variation ids whose PRODUCT gets the full product rule (a price row of a variation) */
    private array $variationProducts = [];

    /** @var array<string, true> media ids: the products (and other subjects) that print them */
    private array $media = [];

    /** @var array<string, true> category ids: their own key plus the ancestors' */
    private array $categories = [];

    /** Keys that are valid for the closed list; anything else is ignored (SurrogateKeys logs it on the page side). */
    public function add(string ...$keys): void
    {
        foreach ($keys as $key) {
            preg_match(SurrogateKeys::PATTERN, $key) === 1 && $this->keys[$key] = true;
        }
    }

    /**
     * The PRODUCT RULE: `p{id}`, `home`, `sitemap` and every subject key of the product (resolved at flush for a purger that does something).
     *
     * @param  iterable<int|string>  $ids
     */
    public function addProducts(iterable $ids): void
    {
        foreach ($ids as $id) {
            $id = (string) $id;
            ctype_digit($id) && $id !== '0' && $this->products[$id] = true;
        }
    }

    /**
     * The product of each variation gets `p{id}` only.
     *
     * @param  iterable<int|string>  $ids
     */
    public function addVariations(iterable $ids): void
    {
        foreach ($ids as $id) {
            $id = (string) $id;
            ctype_digit($id) && $id !== '0' && $this->variations[$id] = true;
        }
    }

    /**
     * The product rule for the products OF these variations (resolved at flush: no query at write time).
     *
     * @param  iterable<int|string>  $ids
     */
    public function addVariationProducts(iterable $ids): void
    {
        foreach ($ids as $id) {
            $id = (string) $id;
            ctype_digit($id) && $id !== '0' && $this->variationProducts[$id] = true;
        }
    }

    /**
     * The category / tag / collection keys of products that are about to LOSE their pivot rows (a delete): read NOW, because at flush the rows are gone. Exactly
     * three grouped statements, whatever the number of products; only for a purger that does something (the NullPurger needs no subject). Admin / delete path only.
     *
     * @param  iterable<int|string>  $ids
     */
    public function addSubjectsOf(iterable $ids): void
    {
        $ids = array_values(array_filter(array_map('strval', is_array($ids) ? $ids : iterator_to_array($ids, false)), static fn (string $id): bool => ctype_digit($id) && $id !== '0'));

        if ($ids === [] || ! app(CachePurger::class)->isActive()) {
            return;
        }

        $this->categories += array_fill_keys(array_map('strval', DB::table('catalog_product_categories')->whereIn('product_id', $ids)->pluck('category_id')->all()), true);

        foreach (DB::table('catalog_product_tags')->whereIn('product_id', $ids)->pluck('tag_id') as $id) {
            $this->keys['t'.$id] = true;
        }

        foreach (DB::table('catalog_product_collections')->whereIn('product_id', $ids)->pluck('collection_id') as $id) {
            $this->keys['col'.$id] = true;
        }
    }

    /** @param  iterable<int|string>  $ids  media ids that became ready or were changed */
    public function addMedia(iterable $ids): void
    {
        foreach ($ids as $id) {
            $id = (string) $id;
            ctype_digit($id) && $id !== '0' && $this->media[$id] = true;
        }
    }

    /** @param  iterable<int|string>  $ids  `c{id}` and the keys of the ancestors (a listing shows its descendants' products) */
    public function addCategories(iterable $ids): void
    {
        foreach ($ids as $id) {
            $id = (string) $id;
            ctype_digit($id) && $id !== '0' && $this->categories[$id] = true;
        }
    }

    public function isEmpty(): bool
    {
        return $this->keys === [] && $this->products === [] && $this->variations === [] && $this->variationProducts === [] && $this->media === [] && $this->categories === [];
    }

    /** @return list<string> what is pending right now, WITHOUT any database resolution (for tests) */
    public function pendingKeys(): array
    {
        return array_keys($this->immediate());
    }

    public function flush(): void
    {
        if ($this->isEmpty()) {
            return;
        }

        $pending = [$this->immediate(), array_keys($this->products), array_keys($this->variations), array_keys($this->media), array_keys($this->categories), array_keys($this->variationProducts)];
        $this->reset();

        try {
            [$keys, $products, $variations, $media, $categories, $variationProducts] = $pending;
            $purger = app(CachePurger::class);

            // the Laravel-side caches follow the same events, with or without a page cache
            isset($keys['sitemap']) && CacheVersion::bump(CacheVersion::SITEMAP);
            isset($keys['all']) && CacheVersion::bump(CacheVersion::ALL);

            if (! $purger->isActive()) {
                return;
            }

            $keys += $this->resolve($products, $variations, $media, $categories, $variationProducts);
            $list = array_keys($keys);
            sort($list);

            foreach (array_chunk($list, max(1, (int) config('cache_purge.batch', 100))) as $batch) {
                PurgeCacheKeys::dispatch($batch);
            }
        } catch (Throwable $exception) {
            Log::error('The page-cache purge could not be collected.', ['exception' => $exception::class, 'message' => $exception->getMessage()]);
        }
    }

    public function reset(): void
    {
        $this->keys = [];
        $this->products = [];
        $this->variations = [];
        $this->media = [];
        $this->categories = [];
        $this->variationProducts = [];
    }

    /** @return array<string, true> */
    private function immediate(): array
    {
        $keys = $this->keys;

        foreach (array_keys($this->products) as $id) {
            $keys['p'.$id] = true;
            $keys['home'] = true;
            $keys['sitemap'] = true;
        }

        foreach (array_keys($this->categories) as $id) {
            $keys['c'.$id] = true;
        }

        if ($this->variationProducts !== []) {
            $keys['home'] = true;
            $keys['sitemap'] = true;
        }

        return $keys;
    }

    /**
     * Resolves what the database knows: the products of variations and media, the subject keys of every product, the ancestors of every category. A FIXED number
     * of grouped statements, whatever the number of ids.
     *
     * @param  list<string>  $products
     * @param  list<string>  $variations
     * @param  list<string>  $media
     * @param  list<string>  $categories
     * @param  list<string>  $variationProducts
     * @return array<string, true>
     */
    private function resolve(array $products, array $variations, array $media, array $categories, array $variationProducts): array
    {
        $keys = [];
        $subjectsOf = $products;

        if ($variationProducts !== []) {
            foreach (DB::table('catalog_variations')->whereIn('id', $variationProducts)->pluck('product_id') as $productId) {
                $keys['p'.$productId] = true;
                $subjectsOf[] = (string) $productId;
            }
        }

        if ($variations !== []) {
            foreach (DB::table('catalog_variations')->whereIn('id', $variations)->pluck('product_id') as $productId) {
                $keys['p'.$productId] = true;
            }
        }

        if ($media !== []) {
            foreach (DB::table('catalog_product_media')->whereIn('media_id', $media)->pluck('product_id') as $productId) {
                $keys['p'.$productId] = true;
            }

            foreach (DB::table('catalog_variation_media as vm')->join('catalog_variations as v', 'v.id', '=', 'vm.variation_id')->whereIn('vm.media_id', $media)->pluck('v.product_id') as $productId) {
                $keys['p'.$productId] = true;
            }

            foreach (DB::table('catalog_brands')->whereIn('logo_media_asset_id', $media)->pluck('id') as $id) {
                $keys['b'.$id] = true;
            }

            foreach (DB::table('catalog_seasons')->whereIn('picture_media_asset_id', $media)->pluck('id') as $id) {
                $keys['s'.$id] = true;
            }

            foreach (DB::table('catalog_collections')->whereIn('picture_media_asset_id', $media)->pluck('id') as $id) {
                $keys['col'.$id] = true;
            }

            foreach (DB::table('catalog_categories')->whereIn('picture_media_asset_id', $media)->pluck('id') as $id) {
                $categories[] = (string) $id;
            }
        }

        $subjectsOf = array_values(array_unique($subjectsOf));

        if ($subjectsOf !== []) {
            foreach (DB::table('catalog_product_categories')->whereIn('product_id', $subjectsOf)->pluck('category_id') as $id) {
                $categories[] = (string) $id;
            }

            foreach (DB::table('catalog_products')->whereIn('id', $subjectsOf)->get(['brand_id', 'season_id']) as $row) {
                $row->brand_id !== null && $keys['b'.$row->brand_id] = true;
                $row->season_id !== null && $keys['s'.$row->season_id] = true;
            }

            foreach (DB::table('catalog_product_tags')->whereIn('product_id', $subjectsOf)->pluck('tag_id') as $id) {
                $keys['t'.$id] = true;
            }

            foreach (DB::table('catalog_product_collections')->whereIn('product_id', $subjectsOf)->pluck('collection_id') as $id) {
                $keys['col'.$id] = true;
            }
        }

        if ($categories !== []) {
            $parents = DB::table('catalog_categories')->pluck('parent_id', 'id');

            foreach (array_unique($categories) as $id) {
                $guard = 0;
                $current = (int) $id;

                while ($current > 0 && $guard++ < 50) {
                    $keys['c'.$current] = true;
                    $current = (int) ($parents[$current] ?? 0);
                }
            }
        }

        return $keys;
    }
}
