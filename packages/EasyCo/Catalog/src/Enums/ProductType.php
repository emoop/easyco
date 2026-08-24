<?php

namespace EasyCo\Catalog\Enums;

/**
 * SIMPLE and VARIABLE are NOT separate domain models — both are a Product
 * aggregate with Variations. This enum only toggles which invariant the
 * aggregate enforces on its Variations collection:
 *
 *   SIMPLE   => exactly one Variation, of type UNIVERSAL, never customer-selectable.
 *   VARIABLE => one or more Variations, of type STANDARD, customer-selectable.
 *
 * See Product::changeToVariable() / Product::attemptConvertToSimple() for the
 * transition rules between the two.
 */
enum ProductType: string
{
    case SIMPLE = 'simple';
    case VARIABLE = 'variable';
}
