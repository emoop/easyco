<?php

namespace EasyCo\Catalog\Exceptions;

use InvalidArgumentException;

/**
 * Every refusal of the measurement data layer that is a bad INPUT (MEAS-2): a number that is not one, a value out of range, a measure that is not in the product's template, a size value that
 * is not on the product's size axis, an unknown unit, an empty or overlong name, a bad measure list. Typed so the admin (MEAS-3/4) can translate by `reason`; nothing is written when one is thrown.
 */
final class InvalidMeasurementValueException extends InvalidArgumentException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function notANumber(string $text): self
    {
        return new self("\"{$text}\" is not a valid measurement (digits with at most 2 decimals, e.g. 95, 95.5 or 95,5).", 'not_a_number');
    }

    public static function outOfRange(int $hundredths): self
    {
        return new self("A measurement of {$hundredths} hundredths is out of range (more than 0 and at most 999999.99).", 'out_of_range');
    }

    public static function measureNotInTemplate(string $productId, string $measureId): self
    {
        return new self("Measure \"{$measureId}\" is not in the measurement template of product \"{$productId}\".", 'measure_not_in_template');
    }

    public static function noTemplate(string $productId): self
    {
        return new self("Product \"{$productId}\" has no measurement template.", 'no_template');
    }

    public static function sizeValueNotOnAxis(string $productId, string $sizeValueId): self
    {
        return new self("Size value \"{$sizeValueId}\" is not on the size axis of product \"{$productId}\".", 'size_value_not_on_axis');
    }

    public static function unknownUnit(string $unit): self
    {
        return new self("Unknown measure unit \"{$unit}\".", 'unknown_unit');
    }

    public static function invalidName(string $what): self
    {
        return new self("A {$what} name must be 1 to 120 characters.", 'invalid_name');
    }

    public static function invalidMeasureList(string $why): self
    {
        return new self("A template's measure list is invalid: {$why}.", 'invalid_measure_list');
    }

    public static function unknownReference(string $what, string $id): self
    {
        return new self("Unknown {$what} \"{$id}\".", 'unknown_reference');
    }
}
