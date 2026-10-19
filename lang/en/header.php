<?php

// The admin page "Header" (stage D2): the header builder of the storefront. bg and en change together.
return [
    'title' => 'Header',
    'navigation_label' => 'Header',
    'add_element' => 'Add an element',
    'html_help' => 'Plain formatting only: scripts, styles and unsafe links are removed on save. Up to 4,000 characters.',
    'social_label' => 'Social networks',

    'tabs' => ['desktop' => 'Desktop', 'mobile' => 'Phone'],
    'rows' => ['top' => 'Top row', 'main' => 'Main row', 'bottom' => 'Bottom row'],
    'slots' => ['left' => 'Left', 'center' => 'Centre', 'right' => 'Right'],

    'fields' => [
        'enabled' => 'Show the row',
        'height' => 'Height',
        'height_hint' => 'From :min to :max px. The content is centred in the height.',
        'sticky' => 'The header stays on top when scrolling',
        'drawer' => 'Phone menu (drawer)',
        'drawer_side' => 'Which side it opens from',
    ],

    'warnings' => [
        'categories_source' => 'This menu does not follow "Menus" — choose "Main menu"',
    ],

    'actions' => [
        'save_draft' => 'Save draft',
        'preview' => 'Preview',
        'discard' => 'Restore the published one',
        'publish' => 'Publish',
    ],

    'notices' => [
        'draft_saved' => 'The draft is saved',
        'published' => 'The header is published',
        'discarded' => 'The draft is discarded',
    ],

    'elements' => [
        'logo' => 'Logo',
        'menu' => 'Category menu',
        'menu_toggle' => 'Menu button (hamburger)',
        'search' => 'Search',
        'account' => 'Account',
        'wishlist' => 'Wishlist',
        'cart' => 'Cart',
        'text' => 'Text',
        'html' => 'HTML block',
        'button' => 'Button',
        'social' => 'Social networks',
        'spacer' => 'Spacer',
    ],

    'settings' => [
        'height' => 'Height (px)',
        'source' => 'Source',
        'style' => 'Style',
        'label_visible' => 'Show a label next to the icon',
        'mode' => 'Kind',
        'show_count' => 'Show the count',
        'text' => 'Text',
        'url' => 'Link',
        'html' => 'HTML',
        'label' => 'Label',
        'items' => 'Networks',
        'width' => 'Width',
        'network' => 'Network',
    ],

    'options' => [
        'categories' => 'Categories',
        'links' => 'Links (with a panel for subcategories)',
        'dropdown' => 'Dropdown',
        'mega' => 'Mega menu (a dropdown for now)',
        'icon_row' => 'An icon that opens a search row',
        'field' => 'An inline field',
        'icon' => 'Icon only',
        'icon_label' => 'Icon and label',
        'primary' => 'Primary',
        'outline' => 'Outline',
        'sm' => 'Small',
        'md' => 'Medium',
        'lg' => 'Large',
        'left' => 'From the left',
        'right' => 'From the right',
    ],

    'social' => [
        'facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube',
        'pinterest' => 'Pinterest', 'viber' => 'Viber', 'whatsapp' => 'WhatsApp', 'x' => 'X',
    ],

    'errors' => [
        'unknown_key' => 'Unknown field: :key.',
        'unknown_type' => 'Unknown element: :type.',
        'unknown_setting' => 'Unknown setting: :key.',
        'bad_choice' => 'Not a valid choice.',
        'bad_list' => 'Not a valid list.',
        'bad_element' => 'Not a valid element.',
        'bad_url' => 'Enter a path in the shop (starts with "/") or an address with https://.',
        'bad_https_url' => 'The address must start with https://.',
        'required' => 'This field is required.',
        'too_long' => 'At most :max characters.',
        'not_plain' => 'Plain text only, without control characters.',
        'html_too_long' => 'The HTML block is too long (at most :max characters after cleaning).',
        'int_range' => 'A number from :min to :max.',
        'height_range' => 'The height must be from :min to :max px.',
        'logo_taller' => 'The logo is taller than the row (at most :max px at this row height).',
        'too_many_slot' => 'At most :max elements per place.',
        'too_many_drawer' => 'At most :max elements in the phone menu.',
        'not_in_drawer' => 'The element ":type" cannot be in the phone menu.',
        'one_logo' => 'There can be only one logo.',
        'one_toggle' => 'There can be only one menu button.',
    ],
];
