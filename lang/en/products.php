<?php

return [

    'label' => 'Product',
    'plural_label' => 'Products',

    'tabs' => [
        'general' => 'General',
        'attributes' => 'Attributes',
        'price_stock' => 'Price & Stock',
        'variations' => 'Variations',
    ],

    'attributes_picker' => [
        'add_attribute' => 'Add attribute',
        'attribute_label' => 'Attribute',
        'value_label' => 'Value',
    ],

    'wizard' => [
        'steps' => [
            'general' => 'General',
            'axes' => 'Axes',
            'variations' => 'Variations',
        ],
        'axes' => [
            'attribute_label' => 'Attribute',
            'values_label' => 'Values',
            'add_axis' => 'Add axis',
        ],
        'variations' => [
            'activate_all' => 'Activate all',
            'combination_label' => 'Combination',
            'sku_label' => 'SKU',
            'active_label' => 'Active',
            'edit_all' => 'Edit all',
            'bulk_cost' => 'Set cost for all variations',
            'bulk_stock_quantity' => 'Set stock for all variations',
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
        'variation_photos' => 'Photos',
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

    'base_sku_cascade' => [
        'confirm_heading' => 'Change base SKU and update matching variant SKUs?',
        'confirm_description' => 'Changing the base SKU from ":old" to ":new" will also update every variant SKU that still starts with ":old-" to start with ":new-" instead. A variant SKU you already customized to something else will not be touched.',
        'confirm_continue' => 'Continue',
        'confirm_cancel' => 'Cancel',
    ],

    'sku_adjustment' => [
        'notification_title' => 'Some SKUs were adjusted on save',
        'item' => 'SKU ":submitted" was saved as ":final"',
    ],

    'price_overrides' => [
        'clear_regular_label' => 'Clear regular price overrides',
        'clear_sale_label' => 'Clear sale price overrides',
        'clear_regular_help' => ':count variants currently have their own regular price and will keep it unless you check this.',
        'clear_sale_help' => ':count variants currently have their own sale price and will keep it unless you check this.',
    ],

    'variation_archive' => [
        'button_label' => 'Archive variation',
        'confirm_heading' => 'Archive this variation?',
        'confirm_description' => 'Archiving ":label" removes it from the storefront and checkout immediately, once you save. It is not deleted — re-adding the exact same combination later restores its original identity, SKU and history.',
        'confirm_submit' => 'Archive',
        'confirm_cancel' => 'Cancel',
    ],

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
