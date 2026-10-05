<?php

// Shared UI chrome, not per-resource vocabulary — same deliberate
// exception to the per-resource lang-file convention as
// related_products.php.
return [

    'groups' => [
        'catalog' => 'Каталог',
        'sales' => 'Продажби',
        'admin' => 'Админ',
    ],

    // The panel top bar's own actions, rendered by
    // AdminPanelProvider's render hook (see
    // resources/views/filament/admin/topbar-actions.blade.php). Its
    // theme-switcher half needs no label of its own — Filament ships
    // translated labels for those three buttons.
    'topbar' => [
        'view_store' => 'Към магазина',
    ],

];
