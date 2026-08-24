<?php

namespace EasyCo\Catalog\Enums;

enum VariationType: string
{
    /** The single, non-customer-selectable Variation of a SIMPLE Product. */
    case UNIVERSAL = 'universal';

    /** A customer-selectable Variation of a VARIABLE Product. */
    case STANDARD = 'standard';
}
