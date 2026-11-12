<?php

// P6e-1: «Collections» (the admin resource).
return [

    'label' => 'Collection',
    'plural_label' => 'Collections',

    'fields' => [
        'picture' => 'Picture',
        'name' => 'Name',
        'slug' => 'Slug',
        'products_count' => 'Products',
    ],

    'picture_help' => 'Shown on the collection page. The picture stays in Media if you remove it here.',

    'products_count' => [
        'count' => '{0}No products|{1}1 product|[2,*]:count products',
    ],

    'delete_blocked' => 'Cannot delete “:name” — it is still used by :count product(s). Remove it from every product first (see the product count in the list).',
    'deleted' => 'The collection was deleted.',

];
