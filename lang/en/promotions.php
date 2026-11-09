<?php

// P5b: the admin «Promo codes» screens (Marketing).
return [

    'model' => 'Promo code',
    'plural' => 'Promo codes',
    'navigation_label' => 'Promo codes',

    'sections' => [
        'code_and_discount' => 'Code and discount',
        'conditions' => 'Conditions',
        'limits' => 'Limits',
        'scope' => 'Scope',
        'public' => 'Public code',
    ],

    'fields' => [
        'code' => 'Code',
        'discount_type' => 'Discount type',
        'percentage' => 'Percentage',
        'discount_amount' => 'Discount amount',
        'minimum_spend' => 'Minimum amount',
        'maximum_spend' => 'Maximum amount',
        'new_customers_only' => 'New customers only',
        'exclude_sale_items' => 'Exclude sale items',
        'individual_use_only' => 'Individual use only',
        'usage_limit_total' => 'Total uses',
        'usage_limit_per_customer' => 'Uses per customer',
        'usage_limit_items' => 'Items discounted',
        'valid_from' => 'Valid from',
        'valid_until' => 'Valid until',
        'is_active' => 'Active',
        'scopes' => 'What the discount applies to',
        'scope_type' => 'Type',
        'scope_mode' => 'Rule',
        'scope_reference' => 'Choice',
        'is_public' => 'Public',
        'public_description' => 'Description for the customer',
    ],

    'types' => [
        'percentage' => 'Percentage',
        'fixed_amount' => 'Fixed amount',
    ],

    'scope_types' => [
        'brand' => 'Brand',
        'category' => 'Category',
        'tag' => 'Tag',
        'product' => 'Product',
        'attribute_value' => 'Attribute value',
    ],

    'scope_modes' => [
        'include' => 'Only for',
        'exclude' => 'Except',
    ],

    'hints' => [
        'code' => 'The customer types it in the cart. Upper or lower case does not matter. The code cannot be changed after it is created.',
        'code_locked' => 'The code, the discount type and the discount value cannot be changed after creation. For another one create a new code and switch this one off (the «Duplicate» button).',
        'discount_type' => 'A percentage of the lines or a fixed amount. It cannot be changed after creation.',
        'percentage' => 'Up to two decimals, for example 12.5. From 0.01 to 100.',
        'discount_amount' => 'In the shop currency, for example 10.00.',
        'minimum_spend' => 'The code applies when the cart total is at least this much. Empty means no minimum.',
        'maximum_spend' => 'The code does not apply above this amount. It must be above the minimum.',
        'new_customers_only' => 'Only for a customer with no earlier order. A guest cannot use it.',
        'exclude_sale_items' => 'Items already at a lower promotional price do not get this discount.',
        'individual_use_only' => 'It cannot be combined with another code.',
        'usage_limit_total' => 'How many times the code can be used in total. Empty means unlimited.',
        'usage_limit_per_customer' => 'How many times one customer can use it. Empty means unlimited.',
        'usage_limit_items' => 'The most items that get the discount. Empty means unlimited.',
        'valid_from' => 'From when the code works. Empty means at once.',
        'valid_until' => 'Until when the code works. Empty means no end.',
        'is_active' => 'A switched-off code cannot be applied. A promo code is never deleted, because orders keep its history.',
        'scopes' => 'An empty scope means the whole cart. «Only for» limits the discount to the chosen ones, «Except» leaves them out. They can be combined.',
        'is_public' => 'A public code is shown to every visitor of the cart («Find a promo code»). The code itself is public by design: do not keep it secret.',
        'account_scopes' => 'This code is restricted to specific customers. That restriction is set through the API only: the form shows it, never changes it and leaves it as it is when you save.',
        'public_description' => 'A short text for the customer, up to 120 characters, plain text only.',
    ],

    // P5c: read-only, under the scope (ACCOUNT scopes are set through the API only)
    'account_scopes' => '{1} Restricted to :count customer (set through the API only)|[2,*] Restricted to :count customers (set through the API only)',

    'public_warning' => 'A public code without a condition is a permanent discount. Add a condition: a minimum amount, a category, a first order or an end date.',

    'table' => [
        'discount' => 'Discount',
        'conditions' => 'Conditions',
        'validity' => 'Validity',
        'used' => 'Used',
        'status' => 'Status',
        'public' => 'Public',
        'public_badge' => 'Public',
        'active' => 'Active',
        'inactive' => 'Off',
        'until' => 'until :date',
        'from' => 'from :date',
        'no_limit' => 'no end',
        'conditions_none' => '—',
        'min' => 'min. :amount',
        'max' => 'max. :amount',
        'new_customers' => 'new customers',
        'no_sale_items' => 'no sale items',
        'individual' => 'individual use',
        'scopes' => 'scope: :count',
        'used_of' => ':used of :limit',
        'filter_status' => 'Status',
        'filter_public' => 'Public',
        'filter_public_yes' => 'Public only',
        'filter_public_no' => 'Not public only',
        'empty' => 'No promo codes yet',
        'empty_description' => 'Create the first code customers can type in the cart.',
    ],

    'actions' => [
        'create' => 'New promo code',
        'edit' => 'Edit',
        'duplicate' => 'Duplicate',
        'activate' => 'Switch on',
        'deactivate' => 'Switch off',
        'menu' => 'Actions',
        'add_scope' => 'Add to the scope',
    ],

    'notice' => [
        'created' => 'The promo code was created.',
        'updated' => 'The promo code was saved.',
        'activated' => 'The promo code was switched on.',
        'deactivated' => 'The promo code was switched off.',
    ],

    'errors' => [
        'invalid' => 'The promo code cannot be saved.',
        'code_required' => 'Enter a code.',
        'code_too_long' => 'The code is at most 255 characters.',
        'code_taken' => 'A promo code with this code already exists.',
        'type_required' => 'Choose a discount type.',
        'percentage_invalid' => 'Enter a percentage from 0.01 to 100 with at most two decimals.',
        'maximum_not_above_minimum' => 'The maximum amount must be above the minimum.',
        'limit_invalid' => 'Enter a whole number from 1 up.',
        'date_invalid' => 'Enter a valid date.',
        'until_not_after_from' => 'The end date must be after the start date.',
        'description_required' => 'A public code needs a description for the customer.',
        'description_too_long' => 'The description is at most :max characters.',
        'scope_invalid' => 'Every scope row needs a type, a rule and a choice.',
        'not_found' => 'This promo code no longer exists.',
    ],

];
