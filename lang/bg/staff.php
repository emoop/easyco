<?php

return [

    'label' => 'Служител',
    'plural_label' => 'Персонал',

    'fields' => [
        'name' => 'Име',
        'email' => 'Имейл',
        'password' => 'Парола',
        'password_help_edit' => 'Оставете празно, за да запазите текущата парола',
        'role' => 'Роля',
        'is_active' => 'Активен',
    ],

    'notifications' => [
        'cannot_deactivate_last_active' => 'Не може да деактивирате последния активен служител — това би ви заключило извън панела. Ако наистина е необходимо, изпълнете `php artisan staff:create-administrator --force` на сървъра.',
    ],

];
