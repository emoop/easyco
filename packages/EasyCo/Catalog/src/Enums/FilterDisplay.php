<?php

namespace EasyCo\Catalog\Enums;

/**
 * How an attribute's values are shown in a shop filter (CT-2, CT-3). The set is closed and lives only here: `list` is a vertical list with a checkbox mark (the default), `links` the values as plain text
 * links on one wrapping line (sizes; it replaced the old `buttons`, CT-3), `swatches` colour circles (a value without a colour is a text link in the same row).
 */
enum FilterDisplay: string
{
    case LIST = 'list';
    case LINKS = 'links';
    case SWATCHES = 'swatches';
}
