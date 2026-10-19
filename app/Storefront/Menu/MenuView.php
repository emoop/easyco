<?php

namespace App\Storefront\Menu;

use App\Storefront\ReadModels\CategoryNode;

/** Small view helpers for the menus (D3). */
final class MenuView
{
    /**
     * The shown category tree as compiled menu items (the header's `categories` source, kept from D2): a category with children opens a dropdown.
     *
     * @param  list<CategoryNode>  $nodes
     * @return list<array<string, mixed>>
     */
    public static function fromCategories(array $nodes, bool $top = true): array
    {
        $items = [];

        foreach ($nodes as $node) {
            $children = self::fromCategories($node->children, false);
            $items[] = [
                'type' => 'link',
                'label' => $node->name,
                'url' => $node->url,
                'new_tab' => false,
                'submenu' => $top && $children !== [] ? 'dropdown' : 'none',
                'children' => $children,
            ];
        }

        return $items;
    }
}
