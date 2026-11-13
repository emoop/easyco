<?php

namespace EasyCo\Catalog\Exceptions;

use RuntimeException;

/**
 * A measurement operation that is refused because of the STATE of the data, not the input (MEAS-2): a measure or template that is in use cannot be deleted ("archive it instead"), a measure's unit
 * cannot change once a number exists, a name is taken. `reason` is stable for the admin to translate. Nothing is written when one is thrown.
 */
final class MeasurementRefusedException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function measureInUse(string $measureId, int $templates, int $values): self
    {
        return new self("Measure \"{$measureId}\" is used by {$templates} template(s) and {$values} number(s): archive it instead of deleting it.", 'measure_in_use');
    }

    public static function unitLocked(string $measureId, int $values): self
    {
        return new self("The unit of measure \"{$measureId}\" cannot change: {$values} number(s) are stored in it. Create a new measure instead.", 'unit_locked');
    }

    public static function templateInUse(string $templateId, int $products): self
    {
        return new self("Template \"{$templateId}\" is used by {$products} product(s): archive it instead of deleting it.", 'template_in_use');
    }

    public static function nameTaken(string $what, string $name): self
    {
        return new self("A {$what} named \"{$name}\" already exists.", 'name_taken');
    }
}
