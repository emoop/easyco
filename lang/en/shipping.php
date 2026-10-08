<?php

// The Shipping admin page (shipping-domain-design.md §12.3.5) and the one-sentence
// method summary (§12.4). Only the strings the page and the reader themselves draw:
// a zone, method or class NAME is the merchant's own data and is escaped where it is
// rendered. The two languages carry the SAME placeholders.
return [
    'title' => 'Shipping',
    'navigation_label' => 'Shipping',
    'intro' => 'A read-only overview: every zone in the order it is matched, the methods inside it, and a tool that shows what a customer would be offered.',

    'status' => [
        'heading' => 'Status',
        'zones' => ':count zone|:count zones',
        'active_methods' => ':count active method|:count active methods',
        'no_zone' => 'There is no zone yet: nothing can be shipped until you create one.',
    ],

    'overview' => [
        'heading' => 'Zones and methods',
        'first_match_wins' => 'The first match from the top wins.',
        'edit_zones' => 'Edit zones',
        'zone_number' => 'Zone :number',
        'no_methods' => 'No shipping methods in this zone yet.',
        'no_zones' => 'No zones are configured.',
    ],

    'coverage' => [
        'only_settlements' => 'only: :names',
        'only_postcodes' => 'only postcodes: :count',
    ],

    'summary' => [
        'free' => 'Free',
        'carrier' => 'Carrier: :code · not configured',
        'free_from' => 'free from :amount',
        'pickup_point' => 'pickup point',
        'inactive' => 'inactive',
        'class_rate' => ':class :amount',
    ],

    'try_it' => [
        'heading' => 'Try it',
        'intro' => 'Enter an address and a cart, and see exactly what the customer would be offered.',
        'country' => 'Country',
        'settlement' => 'Settlement',
        'postcode' => 'Postcode',
        'pickup' => 'Pickup point (office or locker)',
        'goods' => 'Goods total after discount',
        'lines' => 'Cart lines',
        'class' => 'Shipping class',
        'quantity' => 'Quantity',
        'no_class' => 'No class',
        'add_line' => 'Add a line',
        'remove_line' => 'Remove',
        'submit' => 'Show the result',
        'invalid_amount' => 'Enter an amount such as 12.50.',

        'result_heading' => 'Result',
        'matched_zone' => 'Matched zone: :name',
        'refused' => 'We do not deliver to this destination.',
        'destination_heading' => 'As the matcher sees it',
        'destination_settlement' => 'Settlement: :value',
        'destination_postcode' => 'Postcode: :value',
        'none' => '(none)',
        'goods' => 'Goods after discount: :amount',
        'no_methods' => 'This zone has no active methods.',
        'price' => 'Price: :amount',
        'needs_quote' => 'Needs a carrier quote',
        'free_above' => 'Free from: :amount',
        'remaining' => 'Add :amount more',
        'hint' => 'Free shipping: :text',
    ],

    // Stage 5c: the zones admin (shipping-domain-design.md §12.3.2).
    'zones' => [
        'navigation_label' => 'Shipping zones',
        'title' => 'Shipping zones',
        'model' => 'zone',
        'plural' => 'zones',
        'order_note' => 'The first zone from the top that matches the address wins. Narrow zones (with settlements or postcodes) go above broader ones.',
        'empty' => 'There is no zone yet.',
        'create' => 'New zone',
        'fields' => [
            'number' => '#',
            'name' => 'Name',
            'country_codes' => 'Countries',
            'settlement_names' => 'Settlements (optional)',
            'postcodes' => 'Postcodes (optional)',
            'coverage' => 'Covers',
            'methods' => 'Methods',
        ],
        'help' => [
            'settlement_names' => 'Leave empty to cover the whole country. Type a name and press Enter. Names are stored as you type them and compared in a normalised form.',
            'postcodes' => 'Leave empty for no narrowing. Spaces and letter case do not matter: the postcode is compared as shown below.',
        ],
        'methods_count' => ':count method|:count methods',
        'actions' => [
            'menu' => 'Actions',
            'edit' => 'Edit',
            'move_up' => 'Move up',
            'move_down' => 'Move down',
            'delete' => 'Delete',
        ],
        'delete' => [
            'heading' => 'Delete the zone :name?',
            'description' => 'The zone is removed for good. It has no methods, so no shipping price depends on it. Placed orders keep their own shipping record.',
            'submit' => 'Delete the zone',
        ],
        'notice' => [
            'created' => 'The zone was created.',
            'updated' => 'The zone was saved.',
            'deleted' => 'The zone was deleted.',
            'moved' => 'The order of the zones was changed.',
            'refused' => 'Not done',
        ],
        'preview' => [
            'heading' => 'As the matcher compares them',
            'settlements' => 'Settlements: :typed → :normalised',
            'postcodes' => 'Postcodes: :typed → :normalised',
            'more' => 'and :count more',
        ],
        'in_use' => 'The zone ":name" still has :count shipping method. Remove or deactivate its methods first.|The zone ":name" still has :count shipping methods. Remove or deactivate its methods first.',
        'not_found' => 'This zone no longer exists. Nothing was changed.',
        'errors' => [
            'invalid' => 'This value is not accepted.',
            'name_required' => 'Enter a name for the zone.',
            'too_long' => 'At most :max characters.',
            'countries_required' => 'Choose at least one country.',
            'country_unknown' => 'Unknown country: :value.',
            'duplicate' => ':value is listed more than once.',
            'too_many' => 'At most :max entries.',
            'settlement_empty' => 'A settlement name cannot be empty.',
            'postcode_invalid' => 'The postcode ":value" is not valid: 2 to 12 letters, digits or hyphens once spaces are removed.',
        ],
    ],
];
