<?php

namespace App\Storefront\Cookies;

/**
 * CC-1: the menu item kind `consent` («Настройки за бисквитки») at READ time. The compiled menus hold a placeholder (`consent: true`); what a visitor sees is decided HERE, from the banner payload
 * the storefront already has (no query):
 *
 *   - the banner is OFF  -> the item is DROPPED (a link that opens nothing must not exist);
 *   - the banner is ON   -> the label is the banner's own «footer link» text (the shop's language), the address is the policy page's URL, or `#` when no PUBLISHED policy page is picked,
 *                           so without JavaScript the link still leads somewhere sensible. The script opens layer 2 on click (event delegation on `[data-consent-open]`).
 *
 * `present()` answers whether a prepared tree (what the layout is about to print) holds such an item: the fallback strip and the admin's warning depend on it.
 */
final class CookieMenuLink
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $menus  menu key => items
     * @param  array<string, mixed>|null  $cookies  the banner payload for the page's language (StorefrontAppearance::cookies()), null while it is off
     * @return array<string, list<array<string, mixed>>>
     */
    public static function apply(array $menus, ?array $cookies): array
    {
        foreach ($menus as $key => $items) {
            $menus[$key] = self::walk($items, $cookies);
        }

        return $menus;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $cookies
     * @return list<array<string, mixed>>
     */
    private static function walk(array $items, ?array $cookies): array
    {
        $out = [];

        foreach ($items as $item) {
            if (($item['consent'] ?? false) === true) {
                if ($cookies === null) {
                    continue;
                }

                $item['label'] = $cookies['texts']['footer_link'];
                $item['url'] = $cookies['policy'] === null ? '#' : '/info/'.rawurlencode($cookies['policy']['slug']);
                $item['new_tab'] = false;
            } elseif (is_array($item['children'] ?? null) && $item['children'] !== []) {
                $item['children'] = self::walk($item['children'], $cookies);
            }

            $out[] = $item;
        }

        return $out;
    }

    /** Does this prepared tree (a menu, a header, a footer) hold a `consent` item? */
    public static function present(mixed $tree): bool
    {
        if (! is_array($tree)) {
            return false;
        }

        if (($tree['consent'] ?? null) === true) {
            return true;
        }

        foreach ($tree as $value) {
            if (is_array($value) && self::present($value)) {
                return true;
            }
        }

        return false;
    }
}
