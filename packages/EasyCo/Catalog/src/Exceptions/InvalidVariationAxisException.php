<?php

namespace EasyCo\Catalog\Exceptions;

use InvalidArgumentException;

final class InvalidVariationAxisException extends InvalidArgumentException
{
    public static function attributeTypeNotUsableAsAxis(string $attributeCode, string $type): self
    {
        return new self(
            "Attribute \"{$attributeCode}\" (type: {$type}) cannot be used as a variation axis. ".
            'Only SELECT attributes have a closed, enumerable value set suitable for generating combinations.'
        );
    }

    public static function missingValueForAxis(string $attributeCode): self
    {
        return new self("No value supplied for variation axis \"{$attributeCode}\".");
    }

    public static function valueSuppliedForNonAxisAttribute(string $attributeCode): self
    {
        return new self(
            "\"{$attributeCode}\" is not declared as a variation axis for this product; ".
            'it cannot be used to build a variation combination.'
        );
    }

    /**
     * A combination referenced an attribute_definition_id that this
     * Product never declared as a variation axis at all (i.e. it isn't in
     * Product::variationAxes()). Distinct from valueSuppliedForNonAxisAttribute
     * (which is used when a human-readable code is available); this variant
     * is used by Product's combination validation where only the raw id is
     * known.
     */
    public static function axisNotDeclaredForProduct(string $attributeDefinitionId): self
    {
        return new self(
            "Attribute definition \"{$attributeDefinitionId}\" is not a declared variation axis for this product."
        );
    }

    /**
     * An AttributeValue was supplied to a VariationAxis whose
     * attribute_definition_id does not match the axis's own definition —
     * e.g. trying to declare a "Color" axis using a value that actually
     * belongs to the "Material" attribute definition.
     */
    public static function valueBelongsToWrongDefinition(
        string $attributeValueId,
        string $expectedAttributeDefinitionId,
        string $actualAttributeDefinitionId
    ): self {
        return new self(
            "Attribute value \"{$attributeValueId}\" belongs to attribute definition ".
            "\"{$actualAttributeDefinitionId}\", not \"{$expectedAttributeDefinitionId}\" — it cannot be ".
            'used as an allowed value for this axis.'
        );
    }

    public static function emptyAxis(string $attributeDefinitionId): self
    {
        return new self(
            "Variation axis for attribute definition \"{$attributeDefinitionId}\" has no allowed values; ".
            'an axis must have at least one value to be usable.'
        );
    }

    /**
     * The supplied value belongs to the right attribute definition, but
     * this Product's merchant never enabled it as an allowed value for
     * that axis (see catalog_product_axis_values).
     */
    public static function valueNotAllowedForAxis(string $attributeDefinitionId, string $attributeValueId): self
    {
        return new self(
            "Value \"{$attributeValueId}\" is not an allowed value for variation axis ".
            "\"{$attributeDefinitionId}\" on this product."
        );
    }
}
