<?php

namespace EasyCo\Catalog;

use EasyCo\Catalog\Exceptions\InvalidVariationAxisException;

/**
 * One variation axis declared by a Product, together with the specific
 * AttributeValues the merchant has enabled for it on that product (i.e.
 * the domain-layer equivalent of a product's catalog_product_axis_values
 * rows for one attribute_definition_id).
 *
 * This is the object that makes "ATTRIBUTE != VARIATION AXIS" and
 * "every attribute used by a Variation must be a declared axis of that
 * Product, with a value that actually belongs to it" enforceable in the
 * domain layer rather than merely assumed — see
 * Product::declareVariationAxes() / Product::addStandardVariation().
 */
final class VariationAxis
{
    /** @var array<string, AttributeValue> keyed by attribute_value id */
    private array $allowedValues = [];

    /**
     * @param AttributeValue[] $allowedValues Must be non-empty; every
     *   value must belong to $attributeDefinition (checked here, not left
     *   to the caller to get right).
     */
    public function __construct(
        private readonly AttributeDefinition $attributeDefinition,
        array $allowedValues,
    ) {
        // Only a SELECT attribute has a closed, enumerable value set —
        // reuses the same rule/exception AttributeDefinition itself
        // enforces, so there is exactly one place this decision is made.
        $this->attributeDefinition->assertUsableAsVariationAxis();

        $definitionId = $this->attributeDefinition->id()
            ?? throw new \LogicException('An AttributeDefinition must be persisted (have an id) before it can be declared as a variation axis.');

        foreach ($allowedValues as $value) {
            if ($value->attributeDefinitionId() !== $definitionId) {
                throw InvalidVariationAxisException::valueBelongsToWrongDefinition(
                    attributeValueId: (string) $value->id(),
                    expectedAttributeDefinitionId: $definitionId,
                    actualAttributeDefinitionId: $value->attributeDefinitionId(),
                );
            }

            $this->allowedValues[$value->id()] = $value;
        }

        if ($this->allowedValues === []) {
            throw InvalidVariationAxisException::emptyAxis($definitionId);
        }
    }

    public function attributeDefinitionId(): string
    {
        // Constructor guarantees this is non-null.
        return $this->attributeDefinition->id();
    }

    public function attributeDefinitionCode(): string
    {
        return $this->attributeDefinition->code();
    }

    /** @return AttributeValue[] */
    public function allowedValues(): array
    {
        return array_values($this->allowedValues);
    }

    /** @return string[] */
    public function allowedValueIds(): array
    {
        // array_keys() on an array with numeric string keys returns ints
        // (PHP's automatic key normalization) — cast back to honor the
        // declared string[] return type.
        return array_map(strval(...), array_keys($this->allowedValues));
    }

    public function isAllowedValueId(string $attributeValueId): bool
    {
        return isset($this->allowedValues[$attributeValueId]);
    }
}
