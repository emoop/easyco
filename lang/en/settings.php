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
        'season_enabled_label' => 'Show "Season" field',
        'season_enabled_help' => 'When off, the season field and filter disappear from products and from the menu.',
        'brand_enabled_label' => 'Show "Brand" field',
        'brand_enabled_help' => 'When off, the brand field and filter disappear from products and from the menu.',
        'tags_enabled_label' => 'Show "Tags" field',
        'tags_enabled_help' => 'When off, the tags field and filter disappear from products and from the menu.',
        'group_enabled_label' => 'Show "Product group" field',
        'group_enabled_help' => 'When off, the product group field and filter disappear from products and from the menu.',
        'field_label' => 'Require product group',
        'field_help' => 'When on, the "Product group" field becomes required when creating/editing a product.',
        'save_label' => 'Save',
        'saved_notification' => 'Settings saved',
    ],

    'activity_log' => [
        'tab_label' => 'Activity Log',
        'enabled_label' => 'Enable the activity log',
        'enabled_help' => 'When on, every product change (created, edited) is recorded in the log.',
        'retention_label' => 'Keep entries for',
        'retention_help' => 'Older entries are deleted automatically at the next scheduled cleanup.',
        'retention_options' => [
            '6' => '6 months',
            '12' => '12 months',
            '18' => '18 months',
        ],
    ],

    // The currency itself (the EUR/BGN/... code) is set from .env
    // (PRICING_DEFAULT_CURRENCY) at install time — see
    // EasyCo\Pricing\DefaultCurrency. This only configures how the
    // symbol is written around an already-resolved amount.
    'currency' => [
        'tab_label' => 'Currency',
        'position_label' => 'Symbol position',
        'position_help' => 'How the currency symbol is written relative to the amount.',
        'position_options' => [
            'prefix' => 'Before, no space (e.g. €49.99)',
            'prefix_space' => 'Before, with space (e.g. € 49.99)',
            'suffix' => 'After, no space (e.g. 49.99€)',
            'suffix_space' => 'After, with space (e.g. 49.99 €)',
        ],
    ],

];
