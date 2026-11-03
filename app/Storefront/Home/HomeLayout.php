<?php

namespace App\Storefront\Home;

/**
 * The layout of the home page (HP-1): `{spacing, sections: [{type, settings}]}`. The DEFAULT is NO sections: the shop then prints the home page it always printed (its editable intro, the category tiles
 * and the newest products). A layout with at least one section takes the page over. A layout that is not valid is never printed: the old page is.
 */
final class HomeLayout
{
    /** @return array{spacing: string, sections: list<array{type: string, settings: array<string, mixed>}>} */
    public static function default(): array
    {
        return ['spacing' => HomeCatalog::DEFAULT_SPACING, 'sections' => []];
    }
}
