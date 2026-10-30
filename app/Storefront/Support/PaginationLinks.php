<?php

namespace App\Storefront\Support;

use App\Storefront\ReadModels\ListingPage;

/**
 * The pagination of a listing as plain data. Every link is the page's own canonical path plus ONLY the whitelisted
 * query keys (`page`, `per_page`, `sort`), and only when they differ from the default: nothing from the request is
 * echoed, so a hostile query string cannot reach a link.
 *
 */
final class PaginationLinks
{
    private const WINDOW = 7;

    private const DEFAULT_PER_PAGE = 24;

    private const DEFAULT_SORT = 'newest';

    /**
     * CD-1a: `pages` is the numbered row: up to WINDOW pages all of them; more: the first, the last and the current page ± 2, a gap of one page filled with its number and a longer one an
     * ellipsis entry (`{ellipsis: true}`, plain text in the view). `$defaultPerPage` is the PUBLISHED «Каталог» number of cards per page: a `per_page` equal to it is left out of the links.
     *
     * @return array{current: int, last: int, prev: ?string, next: ?string, pages: list<array{number: int, url: string, current: bool}|array{ellipsis: true}>}
     */
    public function build(string $baseUrl, ListingPage $listing, int $defaultPerPage = self::DEFAULT_PER_PAGE): array
    {
        $last = max(1, $listing->lastPage);
        $current = $listing->page;
        $url = fn (int $page): string => $this->url($baseUrl, $listing, $page, $defaultPerPage);

        $pages = [];
        $previous = 0;

        foreach ($this->numbers($current, $last) as $n) {
            if ($n - $previous === 2) {
                $pages[] = ['number' => $previous + 1, 'url' => $url($previous + 1), 'current' => $previous + 1 === $current];
            } elseif ($n - $previous > 2 && $previous > 0) {
                $pages[] = ['ellipsis' => true];
            }

            $pages[] = ['number' => $n, 'url' => $url($n), 'current' => $n === $current];
            $previous = $n;
        }

        return [
            'current' => $current,
            'last' => $last,
            'prev' => $current > 1 ? $url($current - 1) : null,
            'next' => $current < $last ? $url($current + 1) : null,
            'pages' => $pages,
        ];
    }

    /** @return list<int> the page numbers that are shown, ascending */
    private function numbers(int $current, int $last): array
    {
        if ($last <= self::WINDOW) {
            return range(1, $last);
        }

        $numbers = [1, $last, ...range(max(1, $current - 2), min($last, $current + 2))];
        $numbers = array_values(array_unique($numbers));
        sort($numbers);

        return $numbers;
    }

    /** The whitelisted, non-default query string of a listing, as a suffix ("" or "?..."). */
    public function suffix(ListingPage $listing, ?int $page = null, int $defaultPerPage = self::DEFAULT_PER_PAGE): string
    {
        $query = [];
        $page ??= $listing->page;

        if ($page > 1) {
            $query['page'] = $page;
        }

        if ($listing->perPage !== $defaultPerPage) {
            $query['per_page'] = $listing->perPage;
        }

        if (($listing->query['sort'] ?? self::DEFAULT_SORT) !== self::DEFAULT_SORT) {
            $query['sort'] = $listing->query['sort'];
        }

        return $query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function url(string $baseUrl, ListingPage $listing, int $page, int $defaultPerPage): string
    {
        return $baseUrl.$this->suffix($listing, $page, $defaultPerPage);
    }
}
