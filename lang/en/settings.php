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

    // The merchant's own time zone (`site.timezone`, applied by
    // App\Http\Middleware\ApplyStoreTimezone to every date/time the panel
    // DISPLAYS — storage stays UTC, which is why the help text says so
    // explicitly: a merchant changing this must be able to tell it is not a
    // data migration).
    'timezone' => [
        'tab_label' => 'Time zone',
        'field_label' => 'Store time zone',
        'field_help' => 'The time zone every date and time is shown in across the admin panel. Orders and everything else keep being recorded in UTC, so changing this changes how times read, never what is stored.',
        'invalid' => 'Enter a real time zone identifier, for example Europe/Sofia.',
    ],

    'country' => [
        'field_label' => 'Store country',
        'field_help' => 'The country the shop is in. Used as the preselected country in forms; nothing assumes a country until you choose one.',
        'placeholder' => 'Not chosen',
        'invalid' => 'Choose a country from the list.',
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

    // Checkout settings page (checkout RN-1, checkout-region-and-note-design.md section 5).
    'checkout_page' => [
        'terms_source_label' => 'Terms and conditions: source',
        'privacy_source_label' => 'Privacy policy: source',
        'source_page' => 'Page',
        'source_url' => 'External address',
        'terms_page_label' => 'Terms and conditions page',
        'privacy_page_label' => 'Privacy policy page',
        'page_placeholder' => 'Choose a published page',
        'terms_page_help' => 'Only published pages are offered. The terms version follows the page: it changes by itself whenever the page text changes.',
        'terms_version_page' => 'Terms version recorded on an order: :version (the page and its current revision).',
        'unpublished_marker' => 'not published',
        'warning_terms_page_unpublished' => 'The chosen terms page is not published (or none is chosen): the checkbox is NOT shown and nothing is required until you choose a published page.',
        'fields_section' => 'Fields at checkout',
        'fields_help' => 'Optional boxes and the consent the customer sees on the checkout page. No box is ever ticked in advance.',
        'call_before_label' => 'Show "Call me before shipping"',
        'call_before_help' => 'An optional box under the phone field. The answer is stored on the order and a phone icon marks the order in the list.',
        'newsletter_label' => 'Show "Subscribe me to the newsletter"',
        'newsletter_help' => 'An optional box under the names. The order records the ticked sentence as the proof of consent; nothing is sent by it yet.',
        'terms_label' => 'Require acceptance of the terms and conditions',
        'terms_help' => 'On by default. The box is shown only when this is on AND a terms page is set below.',
        'terms_url_label' => 'Terms and conditions page (URL)',
        'terms_version_label' => 'Terms version',
        'terms_version_help' => 'Set automatically: today\'s date when the terms URL changes, or with the button below.',
        'terms_updated_button' => 'The terms were updated',
        'terms_version_bumped' => 'Terms version set to :version.',
        'privacy_url_label' => 'Privacy policy page (URL)',
        'link_invalid' => 'Enter an http(s) address or a path of this store starting with a single "/".',
        'warning_terms_without_url' => 'Terms are required but no terms page is set: the checkbox is NOT shown and nothing is required until you set one.',
        'warning_no_privacy_url' => 'No privacy policy page is set: the privacy notice is not shown on the checkout page.',
        'title' => 'Checkout',
        'navigation_label' => 'Checkout',
        'region_section' => 'Region (state) field',
        'region_help' => 'The region (state, province) of a street address. It is shown and optional by default; choose a different default, or make exceptions for some countries. A pickup point has no region.',
        'default_label' => 'Default for all countries',
        'exceptions_label' => 'Exceptions by country',
        'add_exception' => 'Add a country',
        'country_label' => 'Country',
        'mode_label' => 'Region field',
        'mode' => [
            'optional' => 'Shown, optional',
            'required' => 'Shown, required',
            'hidden' => 'Hidden',
        ],
        'fact_default' => [
            'optional' => 'Shown and optional for every country',
            'required' => 'Shown and required for every country',
            'hidden' => 'Hidden for every country',
        ],
        'fact_except' => 'except:',
        'fact' => [
            'optional' => 'shown, optional',
            'required' => 'required',
            'hidden' => 'hidden',
        ],
        'invoice_section' => 'Invoice',
        'invoice_help' => 'Whether customers can ask for an invoice at checkout, and what each country needs from a company. These are rules YOU set: nothing is pre-filled and no format is assumed. Confirm the formats with your accountant.',
        'invoice_enabled_label' => 'Offer an invoice at checkout',
        'invoice_enabled_help' => 'On by default: in many countries a customer must be able to ask for one. Off removes the box from the checkout and the order refuses an invoice request.',
        'invoice_rules_label' => 'Rules by country',
        'invoice_add_rule' => 'Add a country',
        'invoice_tax_id_label' => 'Name of the company tax number',
        'invoice_tax_id_required' => 'A company must give its tax number',
        'invoice_tax_id_pattern' => 'Format of the tax number (regular expression)',
        'invoice_pattern_hint' => 'Confirm the format with your accountant. Empty = no format check. Example: ^[0-9]{9,13}$',
        'invoice_pattern_invalid' => 'This is not a valid regular expression.',
        'invoice_registration_label' => 'Name of the registration / VAT number',
        'invoice_registration_required' => 'A company must give its registration number',
        'invoice_representative_label' => 'Name of the representative',
        'invoice_rules_invalid' => 'Each country may be listed once, with valid labels and a format that works.',
        'privacy_section' => 'Privacy',
        'privacy_help' => 'How long the customer address of an order is kept in full.',
        'ip_retention_label' => 'Keep the full customer IP address for (days)',
        'ip_retention_help' => 'After this many days the address is replaced by its anonymised form. It is kept only for fraud, security and dispute evidence, never for marketing. 0 = never keep the full address.',
        'exceptions_invalid' => 'Each country may be listed once, with a mode.',
        'save_label' => 'Save',
        'saved_notification' => 'Settings saved',
    ],
];
