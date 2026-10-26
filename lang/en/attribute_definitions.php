<?php

return [

    'label' => 'Attribute',
    'plural_label' => 'Attributes',

    'fields' => [
        'code' => 'Code',
        'name' => 'Name',
        'type' => 'Type',
        'descriptive_count' => 'Used descriptively',
        'axis_count' => 'Used as a variation option',
        'is_filterable' => 'Can be filtered in the shop',
        'is_filterable_help' => 'Only such attributes can be used in the shop\'s filters. For now this changes nothing for visitors.',
        'is_filterable_column' => 'Filter',
        'filter_display' => 'How its values are shown in a filter',
    ],

    'filter_display' => [
        'list' => 'List',
        'buttons' => 'Buttons',
        'swatches' => 'Colour dots',
    ],

    'errors' => [
        'not_closed_list' => 'Only an attribute with a closed list of values can be filterable.',
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
