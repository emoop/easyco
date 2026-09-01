<?php

namespace EasyCo\Catalog;

use InvalidArgumentException;
use LogicException;

/**
 * A merchant-managed product brand (e.g. "Nike", "Adidas") — a simple,
 * standalone, non-Product-owned lookup entity, same shape as
 * AttributeDefinition (plain public constructor, no
 * reconstituteFromStorage() distinction — AttributeDefinition doesn't
 * use one either, so this doesn't invent one). Domain + persistence
 * layer only for now: not yet wired into Product, no HTTP surface yet.
 */
final class Brand
{
    public function __construct(
        private ?string $id,
        private readonly string $name,
        private readonly string $slug,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('Brand name must not be empty.');
        }

        self::assertValidSlug($slug);
    }

    /**
     * Mirrors Product::assertValidSlug() verbatim — see that method's own
     * docblock for the full reasoning (Unicode-aware, no ASCII
     * transliteration, no leading/trailing/consecutive hyphens). Catalog's
     * existing convention is per-class duplication here, not a shared
     * trait/base — the same posture EasyCo\Promotions\PromotionScope took
     * toward EasyCo\Pricing\PriceListScope for structurally similar
     * reasons (promotions-domain-design.md §3.1): no shared abstraction
     * exists yet, so none is introduced here either.
     */
    private static function assertValidSlug(string $slug): void
    {
        if (preg_match('/^[\p{Ll}\p{M}\d]+(-[\p{Ll}\p{M}\d]+)*$/u', $slug) !== 1) {
            throw new InvalidArgumentException(
                "Brand slug \"{$slug}\" is invalid: it must contain only lowercase letters ".
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
            throw new LogicException('Brand already has an id; assignId() is a one-time operation.');
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
}
