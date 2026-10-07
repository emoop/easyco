<?php

// Shared UI chrome, not per-resource vocabulary — same deliberate
// exception to the per-resource lang-file convention as
// related_products.php.
return [

    'groups' => [
        'catalog' => 'Catalog',
        'shipping' => 'Shipping',
        'sales' => 'Sales',
        'admin' => 'Admin',
    ],

    // The panel top bar's own actions, rendered by
    // AdminPanelProvider's render hook (see
    // resources/views/filament/admin/topbar-actions.blade.php). Its
    // theme-switcher half needs no label of its own — Filament ships
    // translated labels for those three buttons.
    'topbar' => [
        'view_store' => 'View store',
    ],

];
