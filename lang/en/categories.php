<?php

return [

    'label' => 'Category',
    'plural_label' => 'Categories',

    'fields' => [
        'picture' => 'Picture',
        'name' => 'Name',
        'slug' => 'Slug',
        'parent_id' => 'Parent category',
        'products_count' => 'Products',
    ],

    'picture_help' => 'Shown on the category page and in the category tiles. The picture stays in Media if you remove it here.',

    'products_count' => [
        'count' => '{0}No products|{1}1 product|[2,*]:count products',
    ],

    'delete_blocked' => 'Cannot delete ":name" — it is still used by :count product(s). Detach it from every product first (see the products count on the list).',
    'deleted' => 'Category deleted.',

];
