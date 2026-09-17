<?php

return [

    // Sidebar-only, generalized — future settings pages (Hero Slider
    // toggle, logo/favicon, etc., site-settings-design.md §1) will
    // share this same group label, not each invent their own.
    'navigation_label' => 'Настройки',

    'locale' => [
        'title' => 'Език',
        'field_label' => 'Език на магазина',
        'field_help' => 'Езикът, използван в административния панел и на витрината.',
        'save_label' => 'Запази',
        'saved_notification' => 'Настройките са запазени',
    ],

    'catalog' => [
        'title' => 'Каталог',
        'field_label' => 'Изисквай артикулна група',
        'field_help' => 'Когато е включено, полето „Артикулна група“ става задължително при създаване/редакция на продукт.',
        'save_label' => 'Запази',
        'saved_notification' => 'Настройките са запазени',
    ],

    'activity_log' => [
        'tab_label' => 'Дневник',
        'enabled_label' => 'Включи дневника на действията',
        'enabled_help' => 'Когато е включено, всяка промяна (създаване, редакция) на продукт се записва в дневника.',
        'retention_label' => 'Съхранявай записите за',
        'retention_help' => 'По-старите записи се изтриват автоматично при следващото планирано изчистване.',
        'retention_options' => [
            '6' => '6 месеца',
            '12' => '12 месеца',
            '18' => '18 месеца',
        ],
    ],

];
