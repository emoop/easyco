<?php

namespace EasyCo\Catalog;

use InvalidArgumentException;

/**
 * Deterministic, normalized signature of a Variation's exact
 * attribute-axis/value combination for one Product.
 *
 * WHY THIS EXISTS:
 * "Product 123 + Color:Black + Size:M must not exist twice" needs a single
 * comparable value the database can put a UNIQUE INDEX on. The source data
 * (which axis maps to which value) lives in a *child* table
 * (catalog_variation_attribute_values), not as inline columns on
 * catalog_variations — so it cannot be a MySQL/Postgres STORED GENERATED
 * COLUMN (those can only read other columns in the same row). Instead this
 * class computes the signature in the application layer, deterministically,
 * and the caller persists it as a plain column alongside the variation
 * inside the same transaction that writes the child rows. The database's
 * UNIQUE INDEX on (product_id, attribute_signature) is what actually
 * enforces the constraint race-condition-safely — see
 * catalog-domain-design.md §"Variation combination uniqueness" for the full
 * reasoning, including why a plain app-layer "check then insert" is not
 * sufficient on its own.
 *
 * DETERMINISM:
 * The signature must be identical no matter what order the caller supplies
 * axis/value pairs in. Values are sorted by attribute_definition_id (as
 * integers) before hashing, so {2 => 'v9', 1 => 'v5'} and {1 => 'v5',
 * 2 => 'v9'} always hash the same.
 *
 * WHY attribute_value IDENTIFIERS, NOT LABELS:
 * Hashing the attribute VALUE ID (not its display label "Black") means
 * renaming a value's label later ("Black" -> "Jet Black") never changes any
 * existing variation's signature or silently creates a duplicate-looking
 * combination.
 */
final class VariationSignature
{
    /**
     * Fixed signature for the single UNIVERSAL variation of a SIMPLE
     * product. Using a constant (rather than an empty-array hash) means the
     * existing UNIQUE(product_id, attribute_signature) index *itself*
     * enforces "a SIMPLE product has exactly one Universal variation" — no
     * separate constraint needed.
     */
    private const UNIVERSAL = 'universal';

    private function __construct(
        private readonly string $value,
    ) {
    }

    public static function forUniversalVariation(): self
    {
        return new self(self::hash(self::UNIVERSAL));
    }

    /**
     * @param array<int|string, int|string> $axisValueIdsByAttributeDefinitionId
     *   Map of attribute_definition_id => attribute_value_id. Must not be
     *   empty — a STANDARD variation always has at least one axis (a
     *   VARIABLE product with zero declared axes is a Catalog-layer
     *   validation error caught upstream, not something this class should
     *   silently tolerate).
     *
     *   NOTE: int and numeric-string ids are equivalent here — PHP itself
     *   normalizes numeric string array keys to int, and string
     *   concatenation stringifies int values identically either way. This
     *   type was widened from the original `array<int, int>` purely to
     *   document that both id representations are accepted; the hashing
     *   algorithm and its output are byte-for-byte unchanged.
     */
    public static function forCombination(array $axisValueIdsByAttributeDefinitionId): self
    {
        if ($axisValueIdsByAttributeDefinitionId === []) {
            throw new InvalidArgumentException(
                'A STANDARD variation combination must have at least one axis/value pair.'
            );
        }

        ksort($axisValueIdsByAttributeDefinitionId, SORT_NUMERIC);

        $canonical = [];
        foreach ($axisValueIdsByAttributeDefinitionId as $attributeDefinitionId => $attributeValueId) {
            $canonical[] = $attributeDefinitionId.':'.$attributeValueId;
        }

        return new self(self::hash(implode('|', $canonical)));
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    private static function hash(string $canonical): string
    {
        return hash('sha256', $canonical);
    }
}
