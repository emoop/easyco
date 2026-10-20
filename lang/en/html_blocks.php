<?php

// "HTML blocks" (stage MENU-2): small reusable pieces of content, for example the picture next to a mega menu. bg and en change together.
return [
    'model' => 'HTML block',
    'plural' => 'HTML blocks',
    'navigation_label' => 'HTML blocks',
    'title' => 'HTML blocks',

    'fields' => [
        'title' => 'Title (for you only)',
        'body' => 'Content',
        'updated_at' => 'Changed',
    ],

    'help' => [
        'body' => 'Headings, bold and italic, lists, a link and a picture. Scripts, styles and embedded frames are not kept.',
    ],

    'actions' => [
        'create' => 'New block',
        'edit' => 'Edit',
        'delete' => 'Delete',
    ],

    'notice' => ['saved' => 'The block was saved.'],

    'errors' => [
        'in_use' => 'The block ":title" is shown by the menu :menus and cannot be deleted.',
    ],
];
