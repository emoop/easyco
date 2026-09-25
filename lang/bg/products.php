<?php

return [

    'label' => 'Продукт',
    'plural_label' => 'Продукти',

    'tabs' => [
        'general' => 'Основни',
        'attributes' => 'Атрибути',
        'price_stock' => 'Цена и наличност',
        'variations' => 'Варианти',
        'axes' => 'Оси за вариации',
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
            'axes' => 'Оси за вариации',
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
            'after_create_help' => 'След създаване → цени и наличности на вариантите',
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
        'is_visible' => 'Видима',
        'variation_active_locked_hint' => 'За да свалите тази вариация от продажба, използвайте „Видима“, „Продаваем“ или я архивирайте.',
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

    // Prefix for a non-uniform PriceRange (list column/View page) —
    // "от 49.99 €". Top-level, not under 'fields': it prefixes a
    // rendered VALUE, it is not itself a field label.
    'price_from' => 'от',

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

    'deletion' => [
        'button_label' => 'Изтрий завинаги',
        'confirm_heading' => 'Окончателно изтриване на този вариант?',
        'confirm_submit' => 'Изтрий завинаги',
        'confirm_cancel' => 'Отказ',
        'impact_intro' => 'Изтриването на :sku (":product") премахва завинаги всичко изброено по-долу. Действието не може да бъде отменено.',
        'impact_attributes' => 'Атрибути: :attributes',
        'impact_stock' => 'Наличност: :quantity',
        'impact_sale_lines' => 'Редове в продажби: :count (нулата е това, което прави варианта изтриваем)',
        'impact_cart_lines' => 'Редове в колички: :open в активни колички, :converted в колички, станали поръчки',
        'impact_price_list_items' => 'Редове в ценоразписи: :count',
        'impact_cost_rows' => 'Редове със себестойност: :count',
        'impact_media' => 'Прикачени медии: :count (самите файлове не се изтриват)',
        'unsaved_edits_warning' => 'Страницата се презарежда от базата данни след изтриването — всички незаписани промени другаде на страницата се губят.',
        'archive_instead' => 'Използвайте „Архивирай варианта“ на този ред вместо това: вариантът остава в каталога, запазва SKU и историята си и може да бъде възстановен по-късно.',
        'refusal' => [
            'has_history' => 'Вариантът „:sku“ има :count реда в продажби и не може да бъде изтрит. Архивирайте го вместо това.',
            'has_stock' => 'Вариантът „:sku“ все още има :count бройки наличност. Задайте наличност 0 или го архивирайте вместо това.',
        ],
        'not_on_this_product' => 'Нищо не беше изтрито: този вариант не принадлежи на този продукт.',
        'field_confirm_label' => 'Разбирам, че действието не може да бъде отменено и че този SKU и баркод стават свободни за използване',
        'field_sku_label' => 'Въведете SKU (:sku) за потвърждение',
        'field_sku_mismatch' => 'Въведеният SKU не съвпада с SKU на варианта.',
        'notification_success' => 'Вариантът :sku беше изтрит завинаги.',
        'notification_not_confirmed' => 'Нищо не беше изтрито: необходими са и отметката за потвърждение, и точният SKU.',
        'notification_unauthorized' => 'Нямате право да изтривате варианти завинаги.',
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

    'actions' => [
        'promote' => 'Избутай напред',
        'promote_confirm_heading' => 'Избутване на този продукт напред?',
        'promote_confirm_description' => '":name" ще се премести най-отпред в продуктовия таймлайн, пред всички останали продукти. Това не променя реалната дата на създаване.',
        'promote_confirm_submit' => 'Избутай напред',
        'promote_confirm_cancel' => 'Отказ',
        'promote_done' => 'Продуктът беше преместен най-отпред в таймлайна.',
        'unpromote' => 'Отмени избутването',
        'unpromote_confirm_heading' => 'Отмяна на избутването?',
        'unpromote_confirm_description' => '":name" ще се върне на естествената си позиция в таймлайна, според реалната дата на създаване.',
        'unpromote_confirm_submit' => 'Отмени',
        'unpromote_confirm_cancel' => 'Отказ',
        'unpromote_done' => 'Продуктът се върна на естествената си позиция в таймлайна.',
    ],

    'create_simple_action' => 'Добави обикновен продукт',
    'create_variable_action' => 'Добави продукт с варианти',

    'created_notification' => [
        'title' => 'Продуктът е създаден',
        'body' => 'Следваща стъпка: цени и наличности на вариантите.',
        'view_action' => 'Прегледай',
    ],

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
