<?php

namespace App\Storefront\Search;

/** One page of a search: the product ids IN RANK ORDER and the totals. The storefront loads the cards for these ids itself (prices, stock and images never come from an engine). */
final readonly class SearchResult
{
    /** @param  list<int>  $ids */
    public function __construct(
        public array $ids,
        public int $total,
        public int $page,
        public int $perPage,
        public int $lastPage,
    ) {
    }
}
