<?php

namespace App\Storefront\ReadModels;

/**
 * A shown category as a page: itself, its canonical path/url (the requested path may have been a wrong prefix),
 * the breadcrumbs of its ancestors (ending with itself) and its shown children. The products are a ListingPage.
 */
final readonly class CategoryPage
{
    /**
     * @param list<Breadcrumb>   $breadcrumbs
     * @param list<CategoryNode> $children
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $path,
        public string $url,
        public int $productCount,
        public array $breadcrumbs,
        public array $children,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'path' => $this->path,
            'url' => $this->url,
            'product_count' => $this->productCount,
            'breadcrumbs' => array_map(static fn (Breadcrumb $b): array => $b->toArray(), $this->breadcrumbs),
            'children' => array_map(static fn (CategoryNode $c): array => $c->toArray(), $this->children),
        ];
    }
}
