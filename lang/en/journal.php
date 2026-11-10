<?php

return [

    'title' => 'Journal',
    'navigation_label' => 'Journal',
    'action_created' => 'Created',
    'system_actor' => 'System',

    // A readable entity name where the journal knows one; other types are shown as stored.
    'entities' => [
        'promotion' => 'Promo code',
        'price_list' => 'Price list',
    ],

    'columns' => [
        'occurred_at' => 'Date',
        'entity_type' => 'Type',
        'entity_id' => 'ID',
        'action' => 'Action',
        'old_value' => 'Old value',
        'new_value' => 'New value',
        'staff_name' => 'Staff member',
    ],

    'filters' => [
        'date_range' => 'Date range',
        'date_from' => 'From',
        'date_until' => 'Until',
    ],

];
