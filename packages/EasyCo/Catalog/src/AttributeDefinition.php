<?php

namespace EasyCo\Catalog;

use EasyCo\Catalog\Enums\AttributeType;
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
        private readonly string $name,
        private readonly AttributeType $type,
    ) {
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

    public function type(): AttributeType
    {
        return $this->type;
    }

    public function assertUsableAsVariationAxis(): void
    {
        if (! $this->type->isUsableAsVariationAxis()) {
            throw InvalidVariationAxisException::attributeTypeNotUsableAsAxis($this->code, $this->type->value);
        }
    }
}
