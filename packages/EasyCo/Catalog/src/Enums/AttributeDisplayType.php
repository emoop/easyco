<?php

namespace EasyCo\Catalog\Enums;

/**
 * How an attribute's values are shown on the product page and in the card (CT-3, product-display-spec.md §4.6). The set is closed and lives only here:
 * `swatch` colour dots (colour), `buttons` small buttons (size), `dropdown` a drop-down list (many values), `specs` only in the specification table
 * (technical characteristics). Nothing renders these on the storefront yet; this is the attribute's own setting, not a theme's.
 */
enum AttributeDisplayType: string
{
    case SWATCH = 'swatch';
    case BUTTONS = 'buttons';
    case DROPDOWN = 'dropdown';
    case SPECS = 'specs';
}
