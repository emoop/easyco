<?php

namespace App\Storefront\ReadModels;

/**
 * A SHOWN category in the tree (it has at least one visible product in itself or a descendant), with its shown
 * children. `product_count` = distinct visible products in the category and all its descendants. `path` is the
 * canonical path: the slugs of the ancestors from the root, then its own, joined by "/".
 */
final readonly class CategoryNode
{
    /**
     * @param list<CategoryNode> $children
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $path,
        public string $url,
        public int $productCount,
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
            'children' => array_map(static fn (CategoryNode $child): array => $child->toArray(), $this->children),
        ];
    }
}
