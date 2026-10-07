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
];
