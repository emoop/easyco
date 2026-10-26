<?php

namespace EasyCo\Catalog;

use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\FilterDisplay;
use EasyCo\Catalog\Exceptions\InvalidAttributeFilterException;
use EasyCo\Catalog\Exceptions\InvalidVariationAxisException;

/**
 * A reusable attribute definition (e.g. "Color", "Material", "Voltage").
 *
 * SCOPE DECISION (see catalog-domain-design.md §"Attribute definition
 * scope"): definitions are global/reusable, but whether a given definition
 * is a descriptive attribute or a variation axis is decided per-Product,
 * not on the definition itself — a "Color" definition can be a variation
 * axis on a T-shirt and a purely descriptive attribute on an accessory
 * that only ships in one color. That per-product decision lives on the
 * product_attributes pivot (is_variation_axis), not here. This class only
 * enforces the one global rule that never changes per product: only a
 * SELECT-typed attribute has a closed enough value set to be usable as an
 * axis at all.
 */
final class AttributeDefinition
{
    public function __construct(
        private ?string $id,
        private readonly string $code,
        private string $name,
        private readonly AttributeType $type,
        // CT-2: may the shop offer this attribute as a filter, and how are its values shown in one. Nothing on the storefront reads them yet.
        private bool $filterable = false,
        private FilterDisplay $filterDisplay = FilterDisplay::LIST,
    ) {
        if ($this->filterable) {
            $this->assertFilterableAllowed();
        }
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new \LogicException('AttributeDefinition already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * `code` and `type` deliberately have no mutator — §3.12's own
     * reasoning: `code` is the stable machine identifier other things
     * key against and `type` drives assertUsableAsVariationAxis()'s
     * hard rule, so changing either after creation is a bigger decision
     * than this pass is scoped to make. Only `name` (the display label)
     * gets one here. No assertion to reuse — the constructor itself
     * validates nothing about `name` (unlike Brand/Category/Tag's own
     * empty-name checks), so none is invented here either.
     */
    public function rename(string $newName): void
    {
        $this->name = $newName;
    }

    public function type(): AttributeType
    {
        return $this->type;
    }

    public function isFilterable(): bool
    {
        return $this->filterable;
    }

    /**
     * Only an attribute with a CLOSED list of values (SELECT, MULTISELECT) can be a filter: a free-text, number or yes/no attribute has no list to offer.
     *
     * @throws InvalidAttributeFilterException
     */
    public function setFilterable(bool $filterable): void
    {
        if ($filterable) {
            $this->assertFilterableAllowed();
        }

        $this->filterable = $filterable;
    }

    public function filterDisplay(): FilterDisplay
    {
        return $this->filterDisplay;
    }

    /**
     * Any attribute may use any display (its values may get pictures later), so only the value itself is checked.
     *
     * @throws InvalidAttributeFilterException
     */
    public function setFilterDisplay(string $value): void
    {
        $this->filterDisplay = FilterDisplay::tryFrom($value) ?? throw InvalidAttributeFilterException::unknownFilterDisplay($value);
    }

    private function assertFilterableAllowed(): void
    {
        if (! in_array($this->type, [AttributeType::SELECT, AttributeType::MULTISELECT], true)) {
            throw InvalidAttributeFilterException::notClosedList($this->code, $this->type->value);
        }
    }

    public function assertUsableAsVariationAxis(): void
    {
        if (! $this->type->isUsableAsVariationAxis()) {
            throw InvalidVariationAxisException::attributeTypeNotUsableAsAxis($this->code, $this->type->value);
        }
    }
}
