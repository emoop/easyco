<?php

namespace EasyCo\Catalog;

/**
 * One enumerable value belonging to a SELECT/MULTISELECT
 * AttributeDefinition (e.g. "Black" under the "Color" definition).
 *
 * Hashed by id (not label) inside VariationSignature — see that class's
 * docblock for why renaming a label must never change a variation's
 * identity.
 */
final class AttributeValue
{
    public function __construct(
        private ?string $id,
        private readonly string $attributeDefinitionId,
        private readonly string $value,
        private readonly int $sortOrder = 0,
    ) {
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new \LogicException('AttributeValue already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function attributeDefinitionId(): string
    {
        return $this->attributeDefinitionId;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }
}
