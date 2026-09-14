<?php

namespace EasyCo\Catalog\Contracts;

use EasyCo\Catalog\Tag;

/**
 * Persistence contract for the global, reusable Tag set — same shape as
 * AttributeDefinitionRepository. Tag is not owned by any single Product,
 * so there is no aggregate-wide transaction concern here.
 */
interface TagRepository
{
    public function save(Tag $tag): void;

    public function findById(string $id): ?Tag;

    /** @return Tag[] */
    public function all(): array;

    /**
     * Count of products attached to this Tag via catalog_product_tags
     * — catalog-domain-design.md §3.13's "is this Tag still in use"
     * check. Same reasoning as CategoryRepository::countProductsUsing():
     * unique(product_id, tag_id) means a plain count() is already a
     * product count, no DISTINCT needed.
     */
    public function countProductsUsing(string $tagId): int;
}
