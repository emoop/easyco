<?php

return [

    'label' => 'Product',
    'plural_label' => 'Products',

    'tabs' => [
        'general' => 'General',
        'attributes' => 'Attributes',
        'price_stock' => 'Price & Stock',
    ],

    'wizard' => [
        'steps' => [
            'general' => 'General',
        ],
    ],

    'fields' => [
        'name' => 'Name',
        'slug' => 'Slug',
        'slug_help' => 'Leave blank to auto-generate from the name.',
        'base_sku' => 'Base SKU',
        'base_sku_help' => 'Leave blank to auto-generate.',
        'barcode' => 'Barcode',
        'description' => 'Description',
        'status' => 'Status',
        'catalog_visibility' => 'Catalog visibility',
        'catalog_visibility_column' => 'Catalog',
        'brand_id' => 'Brand',
        'season_id' => 'Season',
        'product_group_id' => 'Product group',
        'categories' => 'Categories',
        'tags' => 'Tags',
        'is_purchasable' => 'Purchasable',
        'main_photo' => 'Main photo',
        'gallery_photos' => 'Gallery photos',
        'video' => 'Video',
        'video_autoplay' => 'Autoplay on the storefront',
        'thumbnail' => 'Photo',
        'media_upload_hint' => 'Drag & Drop your files or <span class="filepond--label-action">Browse</span> (max :max MB)',
        'status_archive_warning' => 'Archiving permanently deletes the gallery photos and video, keeping only a small thumbnail of the main photo.',
        'regular_price' => 'Regular price',
        'sale_price' => 'Sale price',
        'cost' => 'Cost',
        'stock_quantity' => 'Stock quantity',
        'price_display' => 'Price',
    ],

    'filters' => [
        'archived_only' => 'Show archived only',
    ],

    'status_options' => [
        'draft' => 'Draft',
        'active' => 'Active',
        'archived' => 'Archived',
    ],

    'visibility_options' => [
        'visible' => 'Visible',
        'hidden' => 'Hidden',
    ],

    'base_sku_change_warning' => 'Changing an already-in-use base SKU may no longer match already-printed labels/barcodes.',

    'duplicate_action' => 'Duplicate',
    'duplicate_suffix' => 'copy',

    'create_simple_action' => 'Add simple product',
    'create_variable_action' => 'Add variable product',

    'activity_log' => [
        'title' => 'History',
        'history_button' => 'History',
        'action_created' => 'Created',
        'system_actor' => 'System',
        'columns' => [
            'occurred_at' => 'Date',
            'action' => 'Action',
            'field' => 'Field',
            'old_value' => 'Old value',
            'new_value' => 'New value',
            'staff_name' => 'Staff member',
        ],
    ],

];
