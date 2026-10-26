<?php

namespace EasyCo\Catalog\Enums;

/**
 * How an attribute's values are shown in a shop filter (CT-2). The set is closed and lives only here: `list` is a checkbox list (the default),
 * `buttons` small buttons (sizes), `swatches` colour dots or small pictures (colours; the value's picture is `picture_media_asset_id`).
 * Nothing renders these on the storefront yet.
 */
enum FilterDisplay: string
{
    case LIST = 'list';
    case BUTTONS = 'buttons';
    case SWATCHES = 'swatches';
}
