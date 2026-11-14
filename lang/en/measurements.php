<?php

// MEAS-3: the «Measures» and «Size templates» screens (Catalog). The names are the merchant's data; only labels, hints and the translated refusals of the data layer are here.
return [

    'units' => [
        'mm' => 'mm',
        'cm' => 'cm',
        'm' => 'm',
        'in' => 'in',
        'ft' => 'ft',
        'g' => 'g',
        'kg' => 'kg',
    ],

    'kinds' => [
        'by_size' => 'By size',
        'single' => 'General measures',
    ],

    'status' => [
        'active' => 'Active',
        'archived' => 'Archived',
    ],

    'filters' => [
        'show_archived' => 'Show archived',
    ],

    'used_by_templates' => '{0}0 templates|{1}1 template|[2,*]:count templates',
    'used_by_values' => '{0}0 values|{1}1 value|[2,*]:count values',
    'archive_instead' => 'Archive instead',

    'measure' => [
        'label' => 'Measure',
        'plural' => 'Measures',
        'title' => 'Measures',
        'create' => 'New measure',
        'empty' => 'No measures yet',
        'empty_description' => 'A measure is something you measure, for example «Chest». Create them here, then put them in order in a size template.',
        'fields' => [
            'name' => 'Name',
            'unit' => 'Unit',
            'sort' => 'Order',
            'status' => 'Status',
            'used_by' => 'Used in',
        ],
        'help' => [
            'name' => 'What is measured, for example «Chest» or «Length».',
            'unit' => 'Chosen once. After numbers have been entered, the unit cannot change.',
            'sort' => 'A smaller number comes first in the list.',
        ],
        'unit_locked' => 'The unit cannot be changed because numbers have been entered. Create a new measure.',
        'actions' => [
            'edit' => 'Edit',
            'archive' => 'Archive',
            'restore' => 'Restore',
            'delete' => 'Delete',
        ],
        'archive' => [
            'heading' => 'Archive «:name»',
            'description' => 'An archived measure stops showing on the shop; the numbers stay saved.',
            'submit' => 'Archive',
        ],
        'restore' => [
            'heading' => 'Restore «:name»',
            'description' => 'The measure can be chosen in templates again and shows on the shop.',
            'submit' => 'Restore',
        ],
        'delete' => [
            'heading' => 'Delete «:name»',
            'description' => 'The measure is deleted for good. This cannot be undone.',
            'submit' => 'Delete',
        ],
        'notice' => [
            'created' => 'The measure was created.',
            'updated' => 'The measure was saved.',
            'archived' => 'The measure was archived.',
            'restored' => 'The measure was restored.',
            'deleted' => 'The measure was deleted.',
            'refused' => 'Not saved',
        ],
    ],

    'template' => [
        'label' => 'Size template',
        'plural' => 'Size templates',
        'title' => 'Size templates',
        'create' => 'New template',
        'empty' => 'No size templates yet',
        'empty_description' => 'A template is an ordered list of measures, for example «Tops»: chest, waist, length. A product picks one template.',
        'no_measures' => 'No measures',
        'archived_marker' => 'archived',
        'fields' => [
            'name' => 'Name',
            'kind' => 'Kind',
            'sort' => 'Order',
            'measures' => 'Measures',
            'products' => 'Products',
            'status' => 'Status',
        ],
        'kind_locked' => 'The kind cannot change because products that use this template have numbers entered.',
        'help' => [
            'kind' => 'By size: the rows are the sizes of the product (S, M, L…). General measures: one row for the whole product, e.g. a phone, an oven, a bag.',
            'name' => 'For example «Tops» or «Trousers».',
            'sort' => 'A smaller number comes first in the list.',
            'measures' => 'The order here is the order of the columns in the size table. Each measure at most once; up to 20. A template with no measures is saved as a draft.',
        ],
        'add_measure' => 'Add a measure',
        'products_count' => '{0}No products|{1}1 product|[2,*]:count products',
        'actions' => [
            'edit' => 'Edit',
            'archive' => 'Archive',
            'restore' => 'Restore',
            'delete' => 'Delete',
        ],
        'archive' => [
            'heading' => 'Archive «:name»',
            'description' => 'An archived template is not offered for new products. Products that use it, and their numbers, stay unchanged.',
            'submit' => 'Archive',
        ],
        'restore' => [
            'heading' => 'Restore «:name»',
            'description' => 'The template can be chosen for products again.',
            'submit' => 'Restore',
        ],
        'delete' => [
            'heading' => 'Delete «:name»',
            'description' => 'The template is deleted for good. This cannot be undone.',
            'submit' => 'Delete',
        ],
        'notice' => [
            'created' => 'The template was created.',
            'updated' => 'The template was saved.',
            'archived' => 'The template was archived.',
            'restored' => 'The template was restored.',
            'deleted' => 'The template was deleted.',
            'refused' => 'Not saved',
        ],
        'errors' => [
            'measure_archived' => 'An archived measure cannot be added to a template.',
        ],
    ],

    // MEAS-5: the table on the product page
    'storefront' => [
        'caption' => 'Size table: :name',
    ],

    // MEAS-4: the product tab «Sizes»
    'product_tab' => [

        'title' => 'Sizes',
        'template' => 'Size template',
        'template_help' => 'The template decides the columns of the table. It is created in Catalog → Size templates.',
        'open_templates' => 'Go to the size templates',
        'no_table' => 'No size table',
        'none_exist' => 'There are no size templates yet.',
        'create_template' => 'Create a size template',
        'none_single' => 'Simple products only use «General measures» templates, and there is none yet.',
        'create_single_template' => 'Create a «General measures» template',
        'switch_warning' => 'Numbers for measures that the new template does not have are not deleted — they are kept hidden and come back if you return to the old template.',
        'fallback_before' => 'The rows are by «:axis» because none of the attributes of the product is marked as «Size measure».',
        'fallback_link' => 'Open the attribute',
        'no_measures' => 'This template has no measures yet.',
        'no_rows' => 'This template is «By size» and the product has no sizes yet (variation axes).',
        'archived_column' => '(archived — not shown on the site)',
        'hidden_values' => '{1}There is 1 hidden value (from a previous template or size). It is kept.|[2,*]There are :count hidden values (from a previous template or size). They are kept.',
        'copy_first_row' => 'Copy the first row to all',
        'copied' => '{0}No empty cells to fill|{1}1 cell filled|[2,*]:count cells filled',
        'audit_cells_changed' => ':count cells changed',
        'audit_copied' => 'copied from :source',
        'notice' => [
            'refused' => 'Sizes not saved',
        ],
    ],

    // Every refusal of the data layer (InvalidMeasurementValueException / MeasurementRefusedException `reason`) has a text here; a test makes sure no refusal ships untranslated.
    'refusals' => [
        'name_taken' => 'A record with this name already exists.',
        'unit_locked' => 'The unit cannot be changed because numbers have been entered. Create a new measure.',
        'measure_in_use' => 'The measure is used in templates or has numbers and cannot be deleted. Archive it instead.',
        'template_in_use' => 'The template is used by products and cannot be deleted. Archive it instead.',
        'invalid_name' => 'The name must be 1 to 120 characters.',
        'unknown_unit' => 'Unknown unit.',
        'unknown_reference' => 'The record was not found. Reload the page and try again.',
        'invalid_measure_list' => 'The list of measures is not valid: at most 20, each only once.',
        'not_a_number' => 'Not a valid number. Use digits with at most two decimals.',
        'out_of_range' => 'The number is outside the allowed range.',
        'measure_not_in_template' => 'This measure is not in the product\'s template.',
        'no_template' => 'The product has no size template.',
        'size_value_not_on_axis' => 'This value is not a size of the product.',
        'single_row_only' => 'A «General measures» template has only one row for the whole product.',
        'kind_locked' => 'The kind cannot change because products that use this template have numbers entered.',
        'generic' => 'The action is not allowed.',
    ],

];
