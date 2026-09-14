<?php

namespace EasyCo\Catalog;

use InvalidArgumentException;
use LogicException;

/**
 * A merchant-managed product season (e.g. "Spring/Summer 2026") — a
 * simple, standalone, non-Product-owned lookup entity, identical shape
 * to Brand (catalog-domain-design.md §3.13 — confirmed the domain
 * owner's own decision that Season is exactly Brand's shape, nothing
 * more: no logo-equivalent field). Domain + persistence layer only for
 * now: not yet wired into Product's HTTP surface, no admin UI yet.
 */
final class Season
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
            throw new InvalidArgumentException('Season name must not be empty.');
        }
    }

    /**
     * Mirrors Brand::assertValidSlug() verbatim — see that method's own
     * docblock for the full reasoning (Unicode-aware, no ASCII
     * transliteration, no leading/trailing/consecutive hyphens).
     */
    private static function assertValidSlug(string $slug): void
    {
        if (preg_match('/^[\p{Ll}\p{M}\d]+(-[\p{Ll}\p{M}\d]+)*$/u', $slug) !== 1) {
            throw new InvalidArgumentException(
                "Season slug \"{$slug}\" is invalid: it must contain only lowercase letters ".
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
            throw new LogicException('Season already has an id; assignId() is a one-time operation.');
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
