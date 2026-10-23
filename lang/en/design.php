<?php

// The «Design» screen (DS-2a): one screen for the storefront's layout; for now the Header zone works. bg and en change together.
return [
    'title' => 'Design',
    'navigation_label' => 'Design',
    'back_to_admin' => 'Back to admin',
    'soon' => 'coming',

    'zones_title' => 'Zones',
    'zones' => [
        'topbanner' => 'Top banner',
        'header' => 'Header',
        'footer' => 'Footer',
        'checkout' => 'Cart and checkout',
        'content' => 'Content',
        'sidebar' => 'Sidebar',
    ],

    'menu' => ['place_header' => 'The menu of the place (Header)', 'place_drawer' => 'The menu of the place (Drawer)', 'edit_menus' => 'Edit the menus'],

    'devices' => ['desktop' => 'Desktop', 'mobile' => 'Phone'],
    'device_label' => 'View',

    'palette' => [
        'title' => 'Components',
        'hint' => 'Select a cell, then press a component to add it.',
        'no_cell' => 'Select a cell (or a component in it) first.',
        'full' => 'The cell is full (at most :max).',
        'once' => 'This component can appear only once in the header of this view.',
        'not_offered' => 'This component is not offered in the phone menu.',
    ],

    'schema' => [
        'title' => 'Layout',
        'layout_settings' => 'Header settings',
        'disabled' => 'off',
        'drawer' => 'Drawer',
        'drawer_settings' => 'Drawer settings',
        'drawer_note' => 'Search, the menu items and the account and wishlist rows have a fixed order (MENU-4). The list only decides the text, buttons and social networks that follow them.',
        'add_here' => 'Add here',
        'empty' => 'empty',
        'cell' => 'Cell ":name"',
        'row' => 'Row ":name"',
    ],

    'actions' => [
        'apply' => 'Apply',
        'publish' => 'Publish',
        'discard' => 'Revert to published',
        'move_up' => 'Move up',
        'move_down' => 'Move down',
        'move_left' => 'Move left',
        'move_right' => 'Move right',
        'remove' => 'Remove',
        'remove_logo_title' => 'Remove the logo?',
        'remove_logo_body' => 'The header needs a logo. Without it the storefront will not show its logo until you add a new one.',
    ],

    'props' => [
        'title' => 'Properties',
        'nothing' => 'Select a row, a cell or a component to change its properties.',
        'cell' => 'Cell ":name": add components from the bar above and move them with the arrows.',
        'general' => 'General',
        'colors' => 'Colours',
        'bg' => 'Background',
        'fg' => 'Text',
        'text_size' => 'Text size',
        'text_size_default' => 'As before',
        'sizes' => ['s' => 'Small', 'm' => 'Medium', 'l' => 'Large'],
        'layout' => 'Header — :device',
    ],

    'preview' => [
        'title' => 'Header preview',
        'open' => 'Open in a new window',
        'unsaved' => 'There are unapplied changes: press "Apply" to refresh the preview.',
    ],

    'notices' => [
        'applied' => 'The draft is saved',
        'published' => 'The header is published',
        'discarded' => 'The draft is discarded',
        'errors' => 'There are errors — fix what is marked (:count)',
        'refused_title' => 'Not allowed',
        'unknown' => 'There is no such row, cell or component.',
        'cannot_move' => 'The component cannot move that way.',
    ],
];
