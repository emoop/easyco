<?php

namespace EasyCo\Catalog\Enums;

/**
 * The closed list of units a measure can have (MEAS-2). Weight is NOT here: it is the variation's weight_grams. A measure's unit is chosen once and cannot change while any number exists for it.
 */
enum MeasureUnit: string
{
    case MM = 'mm';
    case CM = 'cm';
    case M = 'm';
    case IN = 'in';
    case FT = 'ft';

    /** @return string[] */
    public static function codes(): array
    {
        return array_map(fn (self $unit) => $unit->value, self::cases());
    }
}
