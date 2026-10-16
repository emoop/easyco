<?php

namespace App\Storefront\Header;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

/**
 * Turns a VALIDATED layout into what the header views print: every element gets its VIEW NAME, and an element whose feature does not exist yet (search,
 * account, wishlist: no route) or whose view is missing is dropped, so nothing prints an empty button; a row with nothing left to show is not printed at
 * all. Also tells the layout whether the drawer and the search row are needed.
 */
final class HeaderView
{
    /**
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     */
    public static function prepare(array $layout): array
    {
        $catalog = HeaderCatalog::all();
        $keep = static function (array $element) use ($catalog): ?array {
            $type = $element['type'];
            $route = HeaderCatalog::FEATURE_ROUTES[$type] ?? null;

            if (($route !== null && ! Route::has($route)) || ! isset($catalog[$type]) || ! View::exists($catalog[$type]['view'])) {
                return null;
            }

            return $element + ['view' => $catalog[$type]['view']];
        };

        $searchRow = false;
        $toggle = false;

        foreach (['desktop', 'mobile'] as $name) {
            foreach ($layout[$name]['rows'] as $row => $data) {
                $visible = [];

                foreach (HeaderLayout::SLOTS as $slot) {
                    $visible[$slot] = array_values(array_filter(array_map($keep, $data[$slot])));
                }

                $layout[$name]['rows'][$row] = $visible + $data;
                $layout[$name]['rows'][$row]['visible'] = $data['enabled'] && count(array_merge(...array_values($visible))) > 0;

                foreach ($visible as $elements) {
                    foreach ($elements as $element) {
                        $searchRow = $searchRow || ($element['type'] === 'search' && ($element['settings']['mode'] ?? '') === 'icon_row');
                        $toggle = $toggle || $element['type'] === 'menu_toggle';
                    }
                }
            }
        }

        $layout['mobile']['drawer'] = array_values(array_filter(array_map($keep, $layout['mobile']['drawer'])));
        $layout['needs_drawer'] = $toggle && $layout['mobile']['drawer'] !== [];
        $layout['needs_search_row'] = $searchRow;

        return $layout;
    }
}
