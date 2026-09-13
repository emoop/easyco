<?php

return [

    'label' => 'Роля',
    'plural_label' => 'Роли',

    'fields' => [
        'name' => 'Име',
        'permissions' => 'Права',
    ],

    'table' => [
        'type' => 'Вид',
    ],

    'type' => [
        'system' => 'Системна роля',
        'custom' => 'Потребителска роля',
    ],

    'permission_groups' => [
        'Catalog' => 'Каталог',
        'Cost and pricing' => 'Себестойност и ценообразуване',
        'Orders' => 'Поръчки',
        'Point of sale' => 'Каса',
        'Marketing' => 'Маркетинг',
        'Reporting' => 'Справки',
        'System' => 'Система',
    ],

    'permissions' => [
        'product_view' => 'Преглед на продукти',
        'product_manage' => 'Управление на продукти',
        'taxonomy_manage' => 'Управление на таксономия (марки, категории, етикети)',
        'cost_view' => 'Преглед на себестойност',
        'cost_manage' => 'Управление на себестойност',
        'price_manage' => 'Управление на цени',
        'order_view' => 'Преглед на поръчки',
        'order_manage' => 'Управление на поръчки',
        'refund_cash' => 'Възстановяване в брой',
        'refund_bank' => 'Възстановяване по банков път',
        'pos_operate' => 'Работа с касов апарат',
        'pos_discount' => 'Прилагане на отстъпки на касата',
        'promotion_manage' => 'Управление на промоции',
        'report_view' => 'Преглед на справки',
        'settings_manage' => 'Управление на настройки',
        'staff_manage' => 'Управление на персонала',
        'ai_manage' => 'Управление на AI функционалността',
    ],

];
