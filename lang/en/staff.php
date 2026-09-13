<?php

return [

    'label' => 'Staff Member',
    'plural_label' => 'Staff',

    'fields' => [
        'name' => 'Name',
        'email' => 'Email',
        'password' => 'Password',
        'password_help_edit' => 'Leave blank to keep the current password',
        'role' => 'Role',
        'is_active' => 'Active',
    ],

    'notifications' => [
        'cannot_deactivate_last_active' => 'Cannot deactivate the last active staff member — you would be locked out of the panel. If this is ever needed anyway, run `php artisan staff:create-administrator --force` from the server.',
    ],

];
