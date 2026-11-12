<?php

// P6b: the admin «Price lists» screens (Marketing).
return [

    'model' => 'Price list',
    'plural' => 'Price lists',
    'navigation_label' => 'Price lists',

    'sections' => [
        'campaign' => 'Campaign',
        'scope' => 'Scope',
    ],

    'fields' => [
        'name' => 'Name',
        'percentage' => 'Discount (%)',
        'valid_from' => 'Start',
        'valid_until' => 'End',
        'is_active' => 'Active',
        'replaces_manual_sale' => 'Replaces the manual sale price',
        'scope_brand' => 'Brand',
        'scope_category' => 'Category',
        'scope_tag' => 'Tag',
        'scope_season' => 'Season',
        'scope_collection' => 'Collection',
        'scope_product' => 'Product',
        'scope_attribute_value' => 'Attribute value',
        'confirm_all_products' => 'I confirm it applies to all products',
    ],

    'hints' => [

        'collection_off' => 'The collections are switched off: this campaign applies to no product until you switch them on again.',
        'name' => 'For you only, the customer does not see it. For example «Spring-26 collection».',
        'percentage' => 'How many percent are taken off the regular price, for example 30 or 12.5. From 0.01 to under 100.',
        'valid_from' => 'From when the campaign applies, in the shop time zone (to the minute). Empty means as soon as it is saved and active.',
        'valid_until' => 'Until when the campaign applies, in the shop time zone. Empty means no end. It must be after the start.',
        'is_active' => 'A switched-off campaign changes no price. A price list is never deleted, only switched off.',
        'replaces_manual_sale' => 'Off (the default): a product with a manual sale price keeps it and the campaign does not touch it. On: when a product has both a manual sale price and a campaign price, the customer gets the LOWER of the two. Example: regular 100 €, manual 85 €. Campaign −30% on → 70 €; −10% on → 85 €; −30% off → 85 €.',
        'scopes' => 'An empty scope means all products. Different kinds are AND-ed (like the shop filters), inside one kind it is OR: «Brand X AND season Autumn-26» are the products that are both; «tags A, B AND brand X» is (A or B) and X.',
    ],

    'scope_rule' => 'OR inside a kind, AND between kinds',
    'copy_of' => 'Copy of :name',

    'all_products_warning' => 'A campaign with no scope applies to ALL the products of the shop. Add a scope or confirm below that this is what you want.',

    'affects' => [
        'all' => 'Affects all products',
        'count' => '{0} Affects no products|{1} Affects :count product|[2,*] Affects :count products',
    ],

    'table' => [
        'name' => 'Name',
        'discount' => 'Discount',
        'period' => 'Period',
        'scope' => 'Scope',
        'replaces' => 'Replaces manual sale',
        'status' => 'Status',
        'system' => 'System',
        'status_inactive' => 'Off',
        'status_planned' => 'Planned',
        'status_active' => 'Active',
        'status_expired' => 'Expired',
        'from_until' => 'from :from until :until',
        'from_only' => 'from :from',
        'until_only' => 'until :until',
        'no_limit' => 'no end',
        'all_products' => 'All products',
        'feature_off' => 'feature switched off',
        'scope_kind' => [
            'brand' => 'Brand',
            'category' => 'Category',
            'tag' => 'Tag',
            'season' => 'Season',
            'collection' => 'Collection',
            'product' => 'Product',
            'attribute_value' => 'Attribute',
        ],
        'filter_status' => 'Status',
        'empty' => 'No campaigns yet',
        'empty_description' => 'Create the first campaign, for example a discount for a collection.',
    ],

    'actions' => [
        'create' => 'Create',
        'edit' => 'Edit',
        'duplicate' => 'Duplicate',
        'activate' => 'Switch on',
        'deactivate' => 'Switch off',
        'menu' => 'Actions',
    ],

    'notice' => [
        'created' => 'The price list was created.',
        'updated' => 'The price list was saved.',
        'activated' => 'The price list was switched on.',
        'deactivated' => 'The price list was switched off.',
    ],

    'errors' => [
        'invalid' => 'The price list cannot be saved.',
        'not_found' => 'This price list no longer exists.',
        'system_list' => 'The system price lists are not changed from here.',
        'name_required' => 'Enter a name.',
        'name_too_long' => 'The name is at most 255 characters.',
        'percentage_invalid' => 'Enter a discount above 0 and below 100 with at most two decimals.',
        'date_invalid' => 'Enter a valid date and time.',
        'until_not_after_from' => 'The end must be after the start.',
        'scope_invalid' => 'A choice in the scope is not valid.',
        'confirm_all_products' => 'With no scope the campaign applies to all products: confirm it or add a scope.',
        'scope_band_full' => 'There are already too many campaigns for this scope (the priority band is full). Change the scope of one of the old campaigns and try again.',
    ],

];
