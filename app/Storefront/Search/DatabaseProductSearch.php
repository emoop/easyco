<?php

namespace App\Storefront\Search;

use App\Storefront\Visibility\StorefrontVisibility;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The simple search in the database (SRCH-1 A), the only `ProductSearch` today. Two statements per page whatever the number of results or words: the count and the page of ids.
 *
 * MATCHES: EVERY word must match (AND between words), each in ANY of: the product name, the product SKU, a SKU of one of its variations, the brand's name, the product slug. The slug
 * is also tried against the word TRANSLITERATED to Latin with the shop's one transliteration (`Str::ascii($word, 'bg')`, the same call that makes the menu keys: «рокля» → `roklya`), so a Latin
 * slug is found by the Cyrillic word; a slug in the product's own script is found by the word as typed. No description search (slow and noisy: later, with an engine).
 *
 * VISIBILITY: `StorefrontVisibility::listed()`, the very scope of a category listing (active, visible in the catalog, no soft-deleted row, and available while the shop hides sold-out products): nothing the
 * listing hides can be found here. It is reused, not copied.
 *
 * RANK («Най-подходящи»): the whole query equals a SKU (the product's or a variation's) first, then the name starts with the first word, then the name contains it, then the rest; ties by the listing's order
 * (newest, then id), so the order is total and the pages never overlap.
 */
final class DatabaseProductSearch implements ProductSearch
{
    public function __construct(private readonly StorefrontVisibility $visibility)
    {
    }

    public function search(SearchQuery $query, SearchSort $sort, int $page, int $perPage): SearchResult
    {
        if (! $query->searchable()) {
            return new SearchResult([], 0, 1, $perPage, 1);
        }

        $products = $this->visibility->listed(ProductModel::query())->select('catalog_products.id');

        foreach ($query->words as $index => $word) {
            $this->matchWord($products, $word, $query->likeWords()[$index]);
        }

        $this->order($products, $query, $sort);

        $paginator = $products->paginate($perPage, ['catalog_products.id'], 'page', max(1, $page));

        return new SearchResult(
            ids: array_values(array_map(static fn (ProductModel $row): int => (int) $row->id, $paginator->items())),
            total: $paginator->total(),
            page: $paginator->currentPage(),
            perPage: $paginator->perPage(),
            lastPage: max(1, $paginator->lastPage()),
        );
    }

    /** One word: it has to be found somewhere (OR between the fields). */
    private function matchWord(Builder $products, string $word, string $escaped): void
    {
        $like = '%'.$escaped.'%';
        $slugs = [$like];
        $latin = mb_strtolower(Str::ascii($word, 'bg'));

        if ($latin !== '' && $latin !== mb_strtolower($word)) {
            $slugs[] = '%'.SearchQuery::escapeLike($latin).'%';
        }

        $products->where(function (Builder $either) use ($like, $slugs): void {
            $either->where('catalog_products.name', 'like', $like)
                ->orWhere('catalog_products.base_sku', 'like', $like)
                ->orWhereExists(static function ($variation) use ($like): void {
                    $variation->selectRaw('1')->from('catalog_variations as sv')
                        ->whereColumn('sv.product_id', 'catalog_products.id')->whereNull('sv.deleted_at')->where('sv.sku', 'like', $like);
                })
                ->orWhereIn('catalog_products.brand_id', static function ($brand) use ($like): void {
                    $brand->select('catalog_brands.id')->from('catalog_brands')->where('catalog_brands.name', 'like', $like);
                });

            foreach ($slugs as $slug) {
                $either->orWhere('catalog_products.slug', 'like', $slug);
            }
        });
    }

    private function order(Builder $products, SearchQuery $query, SearchSort $sort): void
    {
        match ($sort) {
            SearchSort::PRICE_ASC => $products->orderByRaw('catalog_products.price_from_minor IS NULL')->orderBy('catalog_products.price_from_minor')->orderByDesc('catalog_products.timeline_at')->orderByDesc('catalog_products.id'),
            SearchSort::PRICE_DESC => $products->orderByRaw('catalog_products.price_from_minor IS NULL')->orderByDesc('catalog_products.price_from_minor')->orderByDesc('catalog_products.timeline_at')->orderByDesc('catalog_products.id'),
            SearchSort::NEWEST => $products->orderByDesc('catalog_products.timeline_at')->orderByDesc('catalog_products.id'),
            SearchSort::RELEVANCE => $products->orderByRaw(
                'CASE WHEN catalog_products.base_sku = ? OR EXISTS (SELECT 1 FROM catalog_variations rv WHERE rv.product_id = catalog_products.id AND rv.deleted_at IS NULL AND rv.sku = ?) THEN 0 '
                .'WHEN catalog_products.name LIKE ? THEN 1 WHEN catalog_products.name LIKE ? THEN 2 ELSE 3 END',
                [$query->text, $query->text, $query->likeWords()[0].'%', '%'.$query->likeWords()[0].'%'],
            )->orderByDesc('catalog_products.timeline_at')->orderByDesc('catalog_products.id'),
        };
    }
}
