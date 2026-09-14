<?php

return [

    'label' => 'Attribute Definition',
    'plural_label' => 'Attribute Definitions',

    'fields' => [
        'code' => 'Code',
        'name' => 'Name',
        'type' => 'Type',
        'descriptive_count' => 'Used descriptively',
        'axis_count' => 'Used as a variation option',
    ],

    'types' => [
        'text' => 'Text',
        'number' => 'Number',
        'boolean' => 'Yes / No',
        'select' => 'Single choice',
        'multiselect' => 'Multiple choice',
    ],

    // Each half is independently a real, clickable link to its own
    // drill-down scope when nonzero, plain text at zero.
    'products_count' => [
        'descriptive' => '{0}0|{1}1 product|[2,*]:count products',
        'axis' => '{0}0|{1}1 product|[2,*]:count products',
    ],

    'delete_blocked' => [
        'descriptive_only' => 'Cannot delete ":name" — it is used descriptively by :descriptive product(s). Remove all usage before deleting.',
        'axis_only' => 'Cannot delete ":name" — it is used as a variation option by :axis product(s). Remove all usage before deleting.',
        'both' => 'Cannot delete ":name" — it is used descriptively by :descriptive product(s) and as a variation option by :axis product(s). Remove all usage before deleting.',
    ],
    'deleted' => 'Attribute definition deleted.',

];
