<?php

namespace EasyCo\Catalog\Exceptions;

use InvalidArgumentException;

/**
 * CT-2: an attribute's filter settings that the domain refuses. The messages are for developers; the admin shows its own translated text (the form field carries it).
 */
final class InvalidAttributeFilterException extends InvalidArgumentException
{
    public static function notClosedList(string $attributeCode, string $type): self
    {
        return new self(
            "Attribute \"{$attributeCode}\" (type: {$type}) cannot be filterable. ".
            'Only an attribute with a closed list of values (SELECT or MULTISELECT) can be filterable.'
        );
    }

    public static function unknownFilterDisplay(string $value): self
    {
        return new self('Unknown filter display "'.mb_substr($value, 0, 40).'". Allowed: list, buttons, swatches.');
    }
}
