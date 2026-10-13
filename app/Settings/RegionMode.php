<?php

namespace App\Settings;

/**
 * How the REGION (state / province) field of a street address behaves for a country (checkout RN-1,
 * checkout-region-and-note-design.md section 2.2): shown and optional, shown and required, or not shown and not stored.
 */
enum RegionMode: string
{
    case OPTIONAL = 'optional';
    case REQUIRED = 'required';
    case HIDDEN = 'hidden';
}
