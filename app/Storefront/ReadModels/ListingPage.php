<?php

namespace App\Storefront\ReadModels;

/** One page of a listing. `facets` is an empty array until S7. */
final readonly class ListingPage
{
    /**
     * @param list<ProductCard> $items
     * @param array<string, mixed> $query the validated ListingQuery as it was asked
     * @param list<mixed> $facets
     */
    public function __construct(
        public array $query,
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
        public int $lastPage,
        public array $facets,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'items' => array_map(static fn (ProductCard $card): array => $card->toArray(), $this->items),
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'last_page' => $this->lastPage,
            'facets' => $this->facets,
        ];
    }
}
