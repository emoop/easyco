<?php

return [

    'label' => 'Продукт',
    'plural_label' => 'Продукти',

    'tabs' => [
        'general' => 'Основни',
        'attributes' => 'Атрибути',
        'price_stock' => 'Цена и наличност',
        'variations' => 'Варианти',
        'axes' => 'Оси',
    ],

    'axes' => [
        'attribute_label' => 'Атрибут',
        'values_label' => 'Стойности',
        'add_axis' => 'Добави ос',
    ],

    'attributes_picker' => [
        'add_attribute' => 'Добави атрибут',
        'attribute_label' => 'Атрибут',
        'value_label' => 'Стойност',
    ],

    'wizard' => [
        'steps' => [
            'general' => 'Основни данни',
            'axes' => 'Оси',
            'variations' => 'Варианти',
        ],
        'axes' => [
            'attribute_label' => 'Атрибут',
            'values_label' => 'Стойности',
            'add_axis' => 'Добави ос',
        ],
        'variations' => [
            'activate_all' => 'Активирай всички',
            'combination_label' => 'Комбинация',
            'sku_label' => 'SKU',
            'active_label' => 'Активен',
            'edit_all' => 'Редактирай всички',
            'bulk_cost' => 'Задай себестойност за всички варианти',
            'bulk_stock_quantity' => 'Задай наличност за всички варианти',
        ],
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
        'catalog_visibility_column' => 'Каталог',
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
        'regular_price' => 'Редовна цена',
        'sale_price' => 'Промоционална цена',
        'cost' => 'Себестойност',
        'stock_quantity' => 'Наличност',
        'price_display' => 'Цена',
        'variation_photos' => 'Снимки',
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

    'base_sku_cascade' => [
        'confirm_heading' => 'Промяна на основния SKU и обновяване на съвпадащите SKU на вариантите?',
        'confirm_description' => 'Промяната на основния SKU от ":old" на ":new" ще обнови и всеки вариант, чийто SKU все още започва с ":old-", към ":new-". SKU на вариант, който вече сте персонализирали по друг начин, няма да бъде докоснат.',
        'confirm_continue' => 'Продължи',
        'confirm_cancel' => 'Отказ',
    ],

    'sku_adjustment' => [
        'notification_title' => 'Някои SKU бяха коригирани при запис',
        'item' => 'SKU ":submitted" беше записан като ":final"',
    ],

    'price_overrides' => [
        'clear_regular_label' => 'Изчисти персонализираните редовни цени',
        'clear_sale_label' => 'Изчисти персонализираните промоционални цени',
        'clear_regular_help' => ':count варианта в момента имат собствена редовна цена и ще я запазят, освен ако не отметнете това.',
        'clear_sale_help' => ':count варианта в момента имат собствена промоционална цена и ще я запазят, освен ако не отметнете това.',
    ],

    'variation_archive' => [
        'button_label' => 'Архивирай варианта',
        'confirm_heading' => 'Архивиране на този вариант?',
        'confirm_description' => 'Архивирането на ":label" го премахва от витрината и поръчките незабавно, след запис. Не се изтрива — повторното добавяне на същата комбинация по-късно възстановява оригиналната му идентичност, SKU и история.',
        'confirm_submit' => 'Архивирай',
        'confirm_cancel' => 'Отказ',
    ],

    'variations_generate' => [
        'button_label' => 'Генерирай липсващите варианти',
        'confirm_heading' => 'Генериране на всяка липсваща комбинация?',
        'confirm_description' => 'Това създава нов чернови вариант за всяка комбинация от стойностите на декларираните оси, която все още не съществува. Архивирана комбинация, чиито стойности на осите все още са разрешени, се ВЪЗСТАНОВЯВА с оригиналния си SKU, а не се създава наново — същото поведение като при ръчно повторно добавяне.',
        'notification_title' => 'Вариантите бяха генерирани',
        'notification_body' => ':created създадени, :restored възстановени.',
    ],

    'new_variations' => [
        'section_label' => 'Добави вариант',
        'sku_label' => 'SKU',
        'add_variation' => 'Добави вариант',
        'combination_help' => 'Изберете по една стойност за всяка декларирана ос, за да добавите точно тази комбинация.',
    ],

    'variation_restore' => [
        'section_label' => 'Архивирани варианти',
        'button_label' => 'Възстанови',
        'confirm_heading' => 'Възстановяване на този вариант?',
        'confirm_description' => 'Възстановяването на ":label" го връща като чернова, със запазени оригинален SKU, баркод и история. Няма да бъде видим или продаваем, докато не го активирате отново.',
        'confirm_submit' => 'Възстанови',
        'confirm_cancel' => 'Отказ',
        'notification_success' => 'Вариантът беше възстановен.',
    ],

    'duplicate_action' => 'Дублирай',
    'duplicate_suffix' => 'копие',

    'create_simple_action' => 'Добави обикновен продукт',
    'create_variable_action' => 'Добави продукт с варианти',

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
