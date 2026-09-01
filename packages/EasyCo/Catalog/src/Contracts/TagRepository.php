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
}
