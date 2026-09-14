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
        private string $value,
        private int $sortOrder = 0,
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

    /**
     * Safe by construction — the class docblock above and
     * VariationSignature's own docblock both confirm hashing is by id,
     * never by label, so renaming a value's display text never changes
     * any existing variation's identity. No assertion to reuse: the
     * constructor validates nothing about `value` either.
     */
    public function rename(string $newValue): void
    {
        $this->value = $newValue;
    }

    /**
     * No assertion to reuse — the constructor validates nothing about
     * `sortOrder` (not even non-negativity), so none is invented here.
     */
    public function changeSortOrder(int $newSortOrder): void
    {
        $this->sortOrder = $newSortOrder;
    }
}
