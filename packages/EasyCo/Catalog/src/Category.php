<?php

namespace EasyCo\Catalog;

use InvalidArgumentException;
use LogicException;

/**
 * A merchant-managed product category, optionally nested under a parent
 * Category — a simple, standalone, non-Product-owned lookup entity, same
 * shape as AttributeDefinition (plain public constructor, no
 * reconstituteFromStorage() distinction). Domain + persistence layer
 * only for now: not yet wired into Product, no HTTP surface yet.
 *
 * NO SELF-REFERENCE CYCLE VALIDATION AT THIS LAYER FOR V1 — deliberately.
 * catalog_categories.parent_id's nullOnDelete() FK is the only real-world
 * safety net right now; a fuller category hierarchy (cycle detection,
 * depth limits, etc.) is explicitly deferred per
 * catalog-domain-design.md §6. Not this prompt's scope to add.
 */
final class Category
{
    public function __construct(
        private ?string $id,
        private readonly ?string $parentId,
        private readonly string $name,
        private readonly string $slug,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('Category name must not be empty.');
        }

        self::assertValidSlug($slug);
    }

    /**
     * Mirrors Product::assertValidSlug() verbatim — see Brand::
     * assertValidSlug()'s own docblock for why this is duplicated per
     * class rather than shared.
     */
    private static function assertValidSlug(string $slug): void
    {
        if (preg_match('/^[\p{Ll}\p{M}\d]+(-[\p{Ll}\p{M}\d]+)*$/u', $slug) !== 1) {
            throw new InvalidArgumentException(
                "Category slug \"{$slug}\" is invalid: it must contain only lowercase letters ".
                '(any script), combining marks, digits, and single hyphens between segments — '.
                'no leading, trailing, or consecutive hyphens, and it must not be empty.'
            );
        }
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('Category already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function parentId(): ?string
    {
        return $this->parentId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }
}
