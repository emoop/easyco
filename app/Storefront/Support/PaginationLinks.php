<?php

namespace App\Storefront\Support;

use App\Storefront\ReadModels\ListingPage;

/**
 * The pagination of a listing as plain data. Every link is the page's own canonical path plus ONLY the whitelisted
 * query keys (`page`, `per_page`, `sort`), and only when they differ from the default: nothing from the request is
 * echoed, so a hostile query string cannot reach a link.
 *
 * @phpstan-type Link array{number: int, url: string, current: bool}
 */
final class PaginationLinks
{
    private const WINDOW = 7;

    private const DEFAULT_PER_PAGE = 24;

    private const DEFAULT_SORT = 'newest';

    /**
     * @return array{current: int, last: int, prev: ?string, next: ?string, pages: list<array{number: int, url: string, current: bool}>}
     */
    public function build(string $baseUrl, ListingPage $listing): array
    {
        $last = max(1, $listing->lastPage);
        $current = $listing->page;
        $url = fn (int $page): string => $this->url($baseUrl, $listing, $page);

        $from = max(1, min($current - intdiv(self::WINDOW, 2), $last - self::WINDOW + 1));
        $to = min($last, $from + self::WINDOW - 1);

        $pages = [];

        for ($n = $from; $n <= $to; $n++) {
            $pages[] = ['number' => $n, 'url' => $url($n), 'current' => $n === $current];
        }

        return [
            'current' => $current,
            'last' => $last,
            'prev' => $current > 1 ? $url($current - 1) : null,
            'next' => $current < $last ? $url($current + 1) : null,
            'pages' => $pages,
        ];
    }

    /** The whitelisted, non-default query string of a listing, as a suffix ("" or "?..."). */
    public function suffix(ListingPage $listing, ?int $page = null): string
    {
        $query = [];
        $page ??= $listing->page;

        if ($page > 1) {
            $query['page'] = $page;
        }

        if ($listing->perPage !== self::DEFAULT_PER_PAGE) {
            $query['per_page'] = $listing->perPage;
        }

        if (($listing->query['sort'] ?? self::DEFAULT_SORT) !== self::DEFAULT_SORT) {
            $query['sort'] = $listing->query['sort'];
        }

        return $query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function url(string $baseUrl, ListingPage $listing, int $page): string
    {
        return $baseUrl.$this->suffix($listing, $page);
    }
}
