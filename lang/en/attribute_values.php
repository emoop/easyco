<?php

return [

    'label' => 'Attribute Value',
    'plural_label' => 'Attribute Values',
    // Sidebar-only — the resource's own label above stays unchanged
    // for page titles/breadcrumbs/delete confirmations.
    'navigation_label' => 'Attribute Values',

    'fields' => [
        'attribute_definition_id' => 'Attribute',
        'value' => 'Value',
        'sort_order' => 'Sort order',
        'descriptive_count' => 'Used descriptively',
        'axis_count' => 'Used as a variation option',
    ],

    'products_count' => [
        'descriptive' => '{0}0|{1}1 product|[2,*]:count products',
        'axis' => '{0}0|{1}1 product|[2,*]:count products',
    ],

    'delete_blocked' => [
        'descriptive_only' => 'Cannot delete ":name" — it is used descriptively by :descriptive product(s). Remove all usage before deleting.',
        'axis_only' => 'Cannot delete ":name" — it is used as a variation option by :axis product(s). Remove all usage before deleting.',
        'both' => 'Cannot delete ":name" — it is used descriptively by :descriptive product(s) and as a variation option by :axis product(s). Remove all usage before deleting.',
    ],
    'deleted' => 'Attribute value deleted.',

];
