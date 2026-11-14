<?php

namespace EasyCo\Catalog\Enums;

/**
 * What the ROWS of a template's grid are (MEAS-4). `by_size`: one row per size value of the product (a garment). `single`: ONE row for the whole product, whatever its variants (a phone, an oven, a bag).
 */
enum MeasurementTemplateKind: string
{
    case BY_SIZE = 'by_size';
    case SINGLE = 'single';

    /** @return string[] */
    public static function codes(): array
    {
        return array_map(fn (self $kind) => $kind->value, self::cases());
    }
}
