<?php

return [

    'label' => 'Brand',
    'plural_label' => 'Brands',

    'fields' => [
        'name' => 'Name',
        'slug' => 'Slug',
        'logo' => 'Logo',
        'products_count' => 'Products',
    ],

    'products_count' => [
        'count' => '{0}No products|{1}1 product|[2,*]:count products',
    ],

    'delete_blocked' => 'Cannot delete ":name" — it is still used by :count product(s). Detach it from every product first (see the products count on the list).',
    'deleted' => 'Brand deleted.',

];
