<?php

namespace EasyCo\Catalog;

use InvalidArgumentException;
use LogicException;

/**
 * A merchant-managed product tag — a simple, standalone, non-Product-owned
 * lookup entity, same shape as AttributeDefinition (plain public
 * constructor, no reconstituteFromStorage() distinction). Domain +
 * persistence layer only for now: not yet wired into Product, no HTTP
 * surface yet.
 */
final class Tag
{
    public function __construct(
        private ?string $id,
        private string $name,
        private string $slug,
    ) {
        self::assertValidName($name);
        self::assertValidSlug($slug);
    }

    private static function assertValidName(string $name): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('Tag name must not be empty.');
        }
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
                "Tag slug \"{$slug}\" is invalid: it must contain only lowercase letters ".
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
            throw new LogicException('Tag already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function rename(string $newName): void
    {
        self::assertValidName($newName);
        $this->name = $newName;
    }

    public function changeSlug(string $newSlug): void
    {
        self::assertValidSlug($newSlug);
        $this->slug = $newSlug;
    }
}
