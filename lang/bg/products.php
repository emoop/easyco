<?php

return [

    'label' => 'Продукт',
    'plural_label' => 'Продукти',

    'tabs' => [
        'general' => 'Основни',
        'attributes' => 'Атрибути',
        'media' => 'Снимки',
    ],

    'fields' => [
        'name' => 'Име',
        'slug' => 'Слъг',
        'slug_help' => 'Оставете празно, за да се генерира автоматично от името.',
        'base_sku' => 'Основен SKU',
        'base_sku_help' => 'Оставете празно, за да се генерира автоматично.',
        'barcode' => 'Баркод',
        'description' => 'Описание',
        'status' => 'Статус',
        'catalog_visibility' => 'Видимост в каталога',
        'brand_id' => 'Марка',
        'season_id' => 'Сезон',
        'product_group_id' => 'Артикулна група',
        'categories' => 'Категории',
        'tags' => 'Етикети',
        'is_purchasable' => 'Продаваем',
        'photos' => 'Снимки',
    ],

    'status_options' => [
        'draft' => 'Чернова',
        'active' => 'Активен',
        'archived' => 'Архивиран',
    ],

    'visibility_options' => [
        'visible' => 'Видим',
        'hidden' => 'Скрит',
    ],

    'base_sku_change_warning' => 'Промяната на вече използван основен SKU може да не съответства на вече отпечатани етикети/баркодове.',

];
