<?php

namespace EasyCo\Catalog;

use InvalidArgumentException;
use LogicException;

/**
 * A named, reusable default-value bag for Product creation —
 * catalog-domain-design.md §3.16. NOT a live link: applying a template
 * pre-fills a Create form's defaults once; every field stays fully
 * editable afterward, and editing the template later never
 * retroactively touches any product already created from it.
 *
 * brandId/seasonId/productGroupId are plain nullable string
 * references, same cross-domain-by-id posture as Product::assignBrand()/
 * assignSeason() — no existence check performed here. categoryIds/
 * tagIds are plain arrays of string ids, persisted as JSON (§3.16's own
 * "no relational integrity needed beyond these ids existed when the
 * template was saved" reasoning) — this class does not validate that
 * any of these ids actually exist; that is the same posture Product's
 * own assignBrand()/assignSeason() already take.
 */
final class ProductTemplate
{
    /**
     * @param  string[]  $categoryIds
     * @param  string[]  $tagIds
     */
    public function __construct(
        private ?string $id,
        private string $name,
        private ?string $brandId = null,
        private ?string $seasonId = null,
        private ?string $productGroupId = null,
        private array $categoryIds = [],
        private array $tagIds = [],
    ) {
        self::assertValidName($name);
    }

    private static function assertValidName(string $name): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('ProductTemplate name must not be empty.');
        }
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('ProductTemplate already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function rename(string $newName): void
    {
        self::assertValidName($newName);
        $this->name = $newName;
    }

    public function brandId(): ?string
    {
        return $this->brandId;
    }

    public function seasonId(): ?string
    {
        return $this->seasonId;
    }

    public function productGroupId(): ?string
    {
        return $this->productGroupId;
    }

    /** @return string[] */
    public function categoryIds(): array
    {
        return $this->categoryIds;
    }

    /** @return string[] */
    public function tagIds(): array
    {
        return $this->tagIds;
    }

    /**
     * Replaces all five default fields at once — no reason to expose
     * five separate single-field mutators for a bag of suggestions
     * with no invariants between them (§3.16's own reasoning).
     *
     * @param  string[]  $categoryIds
     * @param  string[]  $tagIds
     */
    public function changeDefaults(
        ?string $brandId,
        ?string $seasonId,
        ?string $productGroupId,
        array $categoryIds,
        array $tagIds,
    ): void {
        $this->brandId = $brandId;
        $this->seasonId = $seasonId;
        $this->productGroupId = $productGroupId;
        $this->categoryIds = $categoryIds;
        $this->tagIds = $tagIds;
    }
}
