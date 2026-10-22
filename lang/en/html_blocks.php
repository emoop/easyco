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
        'pictures' => 'Pictures with links',
        'picture' => 'Picture',
        'alt' => 'Picture description (alt)',
        'url' => 'Link when clicked (optional)',
        'updated_at' => 'Changed',
    ],

    'help' => [
        'pictures' => 'A link on text is set with the "link" button in the editor; a link on a picture, here.',
        'body' => 'Headings, bold and italic, lists, a link and a picture. Scripts, styles and embedded frames are not kept.',
    ],

    'actions' => [
        'create' => 'New block',
        'edit' => 'Edit',
        'add_picture' => 'Add a picture',
        'delete' => 'Delete',
    ],

    'notice' => ['saved' => 'The block was saved.'],

    'errors' => [
        'too_many' => 'At most :max pictures.',
        'picture_missing' => 'Choose a picture.',
        'alt' => 'The description is at most :max characters, plain text.',
        'url' => 'The link must be an https:// address or a path in the shop (starts with "/", for example /product-category/boys). javascript:, data:, http: and //address are not allowed.',
        'in_use' => 'The block ":title" is shown by the menu :menus and cannot be deleted.',
        'in_use_footer' => 'The block ":title" is shown by the footer and cannot be deleted.',
        'in_use_both' => 'The block ":title" is shown by the menu :menus and by the footer and cannot be deleted.',
    ],
];
