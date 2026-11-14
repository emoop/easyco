<?php

namespace App\Storefront\Search;

/**
 * THE swap point for storefront search (SRCH-1, search.md). The storefront depends on this interface only; `DatabaseProductSearch` is the one implementation today
 * and an external engine (Meilisearch, ...) replaces it by a different binding in a service provider, touching no storefront file.
 *
 * The contract: `$query` is already normalised and searchable; the result holds the ids of ONE page IN RANK ORDER and the total. The storefront re-checks
 * visibility when it loads the cards (an index may be stale), so an engine may return an id that is no longer shown: it is dropped there.
 */
interface ProductSearch
{
    public function search(SearchQuery $query, SearchSort $sort, int $page, int $perPage): SearchResult;
}
