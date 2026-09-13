<?php

return [

    'label' => 'Role',
    'plural_label' => 'Roles',

    'fields' => [
        'name' => 'Name',
        'permissions' => 'Permissions',
    ],

    'table' => [
        'type' => 'Type',
    ],

    'type' => [
        'system' => 'System role',
        'custom' => 'Custom role',
    ],

    'permission_groups' => [
        'Catalog' => 'Catalog',
        'Cost and pricing' => 'Cost and pricing',
        'Orders' => 'Orders',
        'Point of sale' => 'Point of sale',
        'Marketing' => 'Marketing',
        'Reporting' => 'Reporting',
        'System' => 'System',
    ],

    'permissions' => [
        'product_view' => 'View products',
        'product_manage' => 'Manage products',
        'taxonomy_manage' => 'Manage taxonomy (brands, categories, tags)',
        'cost_view' => 'View cost price',
        'cost_manage' => 'Manage cost price',
        'price_manage' => 'Manage prices',
        'order_view' => 'View orders',
        'order_manage' => 'Manage orders',
        'refund_cash' => 'Refund in cash',
        'refund_bank' => 'Refund via bank',
        'pos_operate' => 'Operate the register',
        'pos_discount' => 'Apply register discounts',
        'promotion_manage' => 'Manage promotions',
        'report_view' => 'View reports',
        'settings_manage' => 'Manage settings',
        'staff_manage' => 'Manage staff',
        'ai_manage' => 'Manage AI functionality',
    ],

];
