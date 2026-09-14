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
 *
 * logoMediaAssetId is a plain string reference to an
 * EasyCo\Media\MediaAsset row — CLAUDE.md rule 9: cross-domain
 * references are always by id/string, never a direct package
 * dependency, unless the referenced thing is a pure value object
 * (Money, not an aggregate). MediaAsset has its own identity and
 * lifecycle, so it does not qualify; Brand never imports
 * EasyCo\Media\MediaAsset at all, mirroring how ProductMedia/
 * VariationMedia handle the same relationship (plain media_id, no
 * domain-layer MediaAsset reference anywhere in Catalog).
 */
final class Brand
{
    public function __construct(
        private ?string $id,
        private string $name,
        private string $slug,
        private ?string $logoMediaAssetId = null,
    ) {
        self::assertValidName($name);
        self::assertValidSlug($slug);
    }

    private static function assertValidName(string $name): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('Brand name must not be empty.');
        }
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

    public function logoMediaAssetId(): ?string
    {
        return $this->logoMediaAssetId;
    }

    /**
     * Takes a plain string id, not a MediaAsset instance — see this
     * class's own docblock for why (rule 9: no direct package
     * dependency on EasyCo\Media). The caller (application layer) is
     * responsible for having already persisted the MediaAsset and
     * passing its real id() here; an empty string is rejected as the
     * one thing this layer CAN verify without importing MediaAsset
     * itself — mirroring Staff::assertRoleIsPersisted()'s "must already
     * be persisted" reasoning as closely as a plain string allows.
     */
    public function setLogo(string $mediaAssetId): void
    {
        if ($mediaAssetId === '') {
            throw new InvalidArgumentException('A Brand\'s logo media asset id must not be empty.');
        }

        $this->logoMediaAssetId = $mediaAssetId;
    }

    public function removeLogo(): void
    {
        $this->logoMediaAssetId = null;
    }
}
