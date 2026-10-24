<?php

// Picture uploads (ML-0): a picture is stored without its hidden data (EXIF / GPS); a format this server cannot read is refused. bg and en change together.
return [
    'malformed' => 'The file is damaged or incomplete — upload it again.',
    'not_cleanable' => 'This format is not supported on this server — install ImageMagick or convert the picture.',

    // «Where used» (ML-1a)
    'usage' => [
        'product_main' => 'Product “:name” — main photo',
        'product_gallery' => 'Product “:name” — gallery',
        'product_video' => 'Product “:name” — video',
        'archived_product' => '(archived product)',
        'variation' => 'Variation “:name”',
        'brand_logo' => 'Brand “:name” — logo',
        'html_block' => 'HTML block “:name” — picture :n',
        'design' => 'Design — :zone',
        'draft' => '(draft)',
        'zone_header' => 'Header',
        'zone_footer' => 'Footer',
        'zone_checkout' => 'Cart and checkout',
    ],

    // The «Media» page (ML-1a)
    'library' => [
        'navigation_label' => 'Media',
        'model' => 'file',
        'plural' => 'Media',
        'title' => 'Media',
        'card' => 'File',
        'empty' => 'No files match.',
        'unused' => 'unused',
        'places' => '{1} 1 place|[2,*] :n places',
        'filters' => ['kind' => 'Kind', 'use' => 'Use', 'state' => 'State', 'month' => 'Month uploaded', 'sort' => 'Sort'],
        'kind' => ['image' => 'Picture', 'video' => 'Video'],
        'use' => ['used' => 'Used', 'unused' => 'Unused'],
        'state' => ['ready' => 'Ready', 'processing' => 'Processing', 'failed' => 'Processing failed', 'missing' => 'File missing', 'video' => 'Video'],
        'sort' => ['newest' => 'Newest', 'largest' => 'Largest', 'name' => 'By name'],
        'details' => [
            'open' => 'Details',
            'close' => 'Close',
            'save' => 'Save',
            'saved' => 'The alt text is saved.',
            'name' => 'File name',
            'type' => 'Kind',
            'size' => 'Size',
            'dimensions' => 'Dimensions (px)',
            'uploaded' => 'Uploaded by',
            'status' => 'State',
            'alt' => 'Alt text',
            'alt_help' => 'Describes the picture for people who cannot see it and for search engines. Up to 200 characters, one line.',
            'alt_invalid' => 'The alt text is up to 200 characters on one line.',
            'copy' => 'Copy the address',
            'copy_disabled' => 'The file is not ready yet.',
            'usage' => 'Where it is used',
            'no_usage' => 'Not used anywhere.',
            'delete_soon' => 'Delete — coming soon',
        ],
    ],
];
