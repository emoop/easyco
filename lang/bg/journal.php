<?php

return [

    'title' => 'Дневник',
    'navigation_label' => 'Дневник',
    'action_created' => 'Създаден',
    'system_actor' => 'Система',

    'columns' => [
        'occurred_at' => 'Дата',
        'entity_type' => 'Тип',
        'entity_id' => 'ID',
        'action' => 'Действие',
        'old_value' => 'Стара стойност',
        'new_value' => 'Нова стойност',
        'staff_name' => 'Служител',
    ],

    'filters' => [
        'date_range' => 'Период',
        'date_from' => 'От',
        'date_until' => 'До',
    ],

];
