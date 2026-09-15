<?php

return [

    // Sidebar-only, generalized — future settings pages (Hero Slider
    // toggle, logo/favicon, etc., site-settings-design.md §1) will
    // share this same group label, not each invent their own.
    'navigation_label' => 'Settings',

    'locale' => [
        'title' => 'Language',
        'field_label' => 'Store language',
        'field_help' => 'The language used across the admin panel and the storefront.',
        'save_label' => 'Save',
        'saved_notification' => 'Settings saved',
    ],

    'catalog' => [
        'title' => 'Catalog',
        'field_label' => 'Require product group',
        'field_help' => 'When on, the "Product group" field becomes required when creating/editing a product.',
        'save_label' => 'Save',
        'saved_notification' => 'Settings saved',
    ],

];
