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
        'topbar' => 'Top banner',
        'header' => 'Header',
        'footer' => 'Footer',
        'checkout' => 'Cart and checkout',
        'content' => 'Content',
        'sidebar' => 'Sidebar',
    ],

    'menu' => ['place_header' => 'The menu of the place (Header)', 'place_drawer' => 'The menu of the place (Drawer)', 'place_footer' => 'The menu of the Footer place', 'edit_menus' => 'Edit the menus', 'edit_blocks' => 'Edit the HTML blocks'],

    'topbar' => [
        'title' => 'Top banner',
        'errors' => [
            'required' => 'Enter a text while the banner is on.',
            'too_long' => 'At most :max characters.',
            'not_plain' => 'Plain text only, without control characters.',
            'bad_date' => 'Not a valid date and time.',
        ],
    ],

    'checkout' => [
        'title' => 'Cart and checkout header',
        'default_label' => 'Back to the shop',
        'pages' => 'Shown on the cart, the checkout and the order-received page instead of the banner and the full header.',
        'phone_hint' => 'The phone is an international number, e.g. +359 888 123 456. Empty = no phone.',
        'fields' => [
            'enabled' => 'Simplified header for the cart and checkout',
            'logo_height_d' => 'Logo height (desktop, px)',
            'logo_height_m' => 'Logo height (phone, px)',
            'back_label' => 'Link text (empty = "Back to the shop")',
            'back_url' => 'Link address',
            'phone' => 'Phone',
            'bg' => 'Background',
            'fg' => 'Text',
        ],
    ],

    'cart' => ['colors' => 'Colours', 'low_contrast' => 'Low contrast between the number and the badge background (:ratio : 1, at least 4.5 : 1 is recommended).'],

    'footer' => [
        'lower' => 'Lower level',
        'line' => 'Text line',
        'newsletter_hint' => 'will show when the newsletter is ready',
        'last_row' => 'The footer needs at least one row.',
        'last_column' => 'The row needs at least one column.',
        'limit_rows' => 'At most :max rows.',
        'limit_columns' => 'At most :max columns in the row.',
        'confirm_needed' => 'Confirm first: it holds blocks.',
        'row_hint' => 'Row: add columns and move them with the arrows.',
        'low_contrast' => 'Low contrast between the text and the background (:ratio : 1, at least 4.5 : 1 is recommended).',
    ],

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
        'save' => 'Save',
        'default' => 'Reset to default',
        'remove_column' => 'Remove the column',
        'remove_column_title' => 'Remove the column?',
        'remove_column_body' => 'The column holds blocks: :blocks. They will be removed too.',
        'remove_row' => 'Remove the row',
        'remove_row_title' => 'Remove the row?',
        'remove_row_body' => 'The row holds blocks: :blocks. They will be removed too.',
        'add_row' => 'Add a row',
        'add_column' => 'Add a column',
        'move_column_left' => 'Move the column left',
        'move_column_right' => 'Move the column right',
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
        'footer' => 'Footer preview',
        'topbar' => 'Home page preview',
        'checkout' => 'Cart preview',
        'open' => 'Open in a new window',
        'unsaved' => 'There are unapplied changes: press "Apply" to refresh the preview.',
    ],

    'notices' => [
        'applied' => 'The draft is saved',
        'published' => 'The header is published',
        'discarded' => 'The draft is discarded',
        'saved' => 'Saved',
        'live_hint' => 'Changes show in the shop at once.',
        'published_header' => 'The header is published',
        'published_footer' => 'The footer is published',
        'published_checkout' => 'The cart and checkout header is published',
        'defaulted' => 'The default footer is set: press "Apply" to save it',
        'too_large' => 'The layout is too large to publish.',
        'errors' => 'There are errors — fix what is marked (:count)',
        'refused_title' => 'Not allowed',
        'unknown' => 'There is no such row, cell or component.',
        'cannot_move' => 'The component cannot move that way.',
    ],
    // LG-1: the logo as a picture
    'logo' => [
        'image' => 'Logo (picture)',
        'empty' => 'The standard logo is used',
        'help' => 'PNG or WebP, ideally with a transparent background. Upload it at least 2 times larger than shown (e.g. for a height of 40 px — about 80–100 px), so it is sharp on phones.',
        'errors' => [
            'missing' => 'This picture does not exist in Media.',
            'not_picture' => 'This is not a picture.',
            'wrong_type' => 'The logo must be a PNG or a WebP.',
            'unknown_type' => 'The type of this picture is not known yet — run media:backfill-meta or upload it again.',
            'not_ready' => 'The picture is still being processed — try again in a moment.',
        ],
    ],

];
