<?php

namespace EasyCo\Catalog\Enums;

/**
 * The closed list of units a measure can have (MEAS-2). `g` / `kg` (MEAS-4) are for the weight SHOWN to customers (a normal measure); the courier weight is the variation's weight_grams and is a different thing. A measure's unit is chosen once and cannot change while any number exists for it.
 */
enum MeasureUnit: string
{
    case MM = 'mm';
    case CM = 'cm';
    case M = 'm';
    case IN = 'in';
    case FT = 'ft';
    case G = 'g';
    case KG = 'kg';

    /** @return string[] */
    public static function codes(): array
    {
        return array_map(fn (self $unit) => $unit->value, self::cases());
    }
}
