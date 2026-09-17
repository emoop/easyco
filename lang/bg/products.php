<?php

return [

    'label' => 'Продукт',
    'plural_label' => 'Продукти',

    'tabs' => [
        'general' => 'Основни',
        'attributes' => 'Атрибути',
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
        'main_photo' => 'Главна снимка',
        'gallery_photos' => 'Малки снимки',
        'video' => 'Видео',
        'video_autoplay' => 'Автоматично стартиране на витрината',
        'thumbnail' => 'Снимка',
        'media_upload_hint' => 'Провлачете файлове тук или <span class="filepond--label-action">разгледайте</span> (макс. :max MB)',
        'status_archive_warning' => 'Архивирането изтрива за постоянно снимките от галерията и видеото, като запазва само малка миниатюра на главната снимка.',
    ],

    'filters' => [
        'archived_only' => 'Покажи само архивирани',
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

    'duplicate_action' => 'Дублирай',
    'duplicate_suffix' => 'копие',

    'activity_log' => [
        'title' => 'История',
        'history_button' => 'История',
        'action_created' => 'Създаден',
        'system_actor' => 'Система',
        'columns' => [
            'occurred_at' => 'Дата',
            'action' => 'Действие',
            'field' => 'Поле',
            'old_value' => 'Стара стойност',
            'new_value' => 'Нова стойност',
            'staff_name' => 'Служител',
        ],
    ],

];
