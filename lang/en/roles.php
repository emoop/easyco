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
        'Shipping' => 'Shipping',
        'Marketing' => 'Marketing',
        'Reporting' => 'Reporting',
        'System' => 'System',
    ],

    'permissions' => [
        'product_view' => 'View products',
        'product_manage' => 'Manage products',
        'product_delete' => 'Delete variations and archived products permanently',
        'taxonomy_manage' => 'Manage taxonomy (brands, categories, tags)',
        'cost_view' => 'View cost price',
        'cost_manage' => 'Manage cost price',
        'price_manage' => 'Manage prices',
        'order_view' => 'View orders',
        'order_manage' => 'Manage orders',
        'refund_cash' => 'Refund in cash',
        'refund_bank' => 'Refund via bank',
        'order_discount' => 'Give order lines a manual discount',
        'payment_reconcile' => 'Accept a bank transfer that is short or over',
        'pos_operate' => 'Operate the register',
        'pos_discount' => 'Apply register discounts',
        'shipping_manage' => 'Manage shipping',
        'promotion_manage' => 'Manage promotions',
        'report_view' => 'View reports',
        'settings_manage' => 'Manage settings',
        'staff_manage' => 'Manage staff',
        'ai_manage' => 'Manage AI functionality',
    ],

];
