<?php

return [

    'label' => 'Product',
    'plural_label' => 'Products',

    'tabs' => [
        'general' => 'General',
        'attributes' => 'Attributes',
        'price_stock' => 'Price & Stock',
        'variations' => 'Variations',
        'axes' => 'Variation axes',
    ],

    'axes' => [
        'attribute_label' => 'Attribute',
        'values_label' => 'Values',
        'add_axis' => 'Add axis',
    ],

    'attributes_picker' => [
        'add_attribute' => 'Add attribute',
        'attribute_label' => 'Attribute',
        'value_label' => 'Value',
    ],

    'wizard' => [
        'steps' => [
            'general' => 'General',
            'axes' => 'Variation axes',
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
            'after_create_help' => 'After creating → prices and stock for the variations',
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

    // Prefix for a non-uniform PriceRange (list column/View page,
    // PriceRange::hasUniformFinalPrice()/hasUniformRegularPrice()/
    // hasUniformDiscountedFinalPrice() false) — "from 49.99 €" /
    // "от 49.99 €". Top-level, not under 'fields': it prefixes a
    // rendered VALUE, it is not itself a field label.
    'price_from' => 'from',

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

    'variations_generate' => [
        'button_label' => 'Generate missing variations',
        'confirm_heading' => 'Generate every missing combination?',
        'confirm_description' => 'This creates a new draft variation for every combination of the declared axis values that does not already exist. An archived combination whose axis values are still enabled is restored with its original SKU, not recreated — the same behavior as re-adding it manually.',
        'notification_title' => 'Variations generated',
        'notification_body' => ':created created, :restored restored.',
    ],

    'new_variations' => [
        'section_label' => 'Add a variation',
        'sku_label' => 'SKU',
        'add_variation' => 'Add variation',
        'combination_help' => 'Choose one value for each declared axis to add exactly that combination.',
    ],

    'variation_restore' => [
        'section_label' => 'Archived variations',
        'button_label' => 'Restore',
        'confirm_heading' => 'Restore this variation?',
        'confirm_description' => 'Restoring ":label" brings it back as a draft, with its original SKU, barcode and history intact. It will not be visible or purchasable until you activate it again.',
        'confirm_submit' => 'Restore',
        'confirm_cancel' => 'Cancel',
        'notification_success' => 'Variation restored.',
    ],

    'duplicate_action' => 'Duplicate',
    'duplicate_suffix' => 'copy',

    'actions' => [
        'promote' => 'Move to front',
        'promote_confirm_heading' => 'Move this product to the front?',
        'promote_confirm_description' => '":name" will move to the front of the product timeline, ahead of every other product. This does not change when it was actually created.',
        'promote_confirm_submit' => 'Move to front',
        'promote_confirm_cancel' => 'Cancel',
        'promote_done' => 'Product moved to the front of the timeline.',
        'unpromote' => 'Undo move to front',
        'unpromote_confirm_heading' => 'Undo this promotion?',
        'unpromote_confirm_description' => '":name" will return to its natural position in the timeline, based on when it was actually created.',
        'unpromote_confirm_submit' => 'Undo',
        'unpromote_confirm_cancel' => 'Cancel',
        'unpromote_done' => 'Product returned to its natural timeline position.',
    ],

    'create_simple_action' => 'Add simple product',
    'create_variable_action' => 'Add variable product',

    'created_notification' => [
        'title' => 'Product created',
        'body' => 'Next step: prices and stock for the variations.',
        'view_action' => 'View',
    ],

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
