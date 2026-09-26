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
        'is_visible' => 'Visible',
        'variation_active_locked_hint' => 'To take this variation off sale, use Visible, Purchasable, or archive it.',
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

    'status_options' => [
        'draft' => 'Draft',
        'active' => 'Active',
        'archived' => 'Archived',
    ],

    // The products list's four status VIEWS (D1): the toolbar button group at
    // the left of the table. The first three are the product's own status
    // values (so the view IS the query constraint), 'all' is the one that adds
    // none. Separate from `status_options` above — the same three words, but a
    // different control with its own plural "All", and a field label must not
    // have to change when a button's wording does.
    'status_views' => [
        'active' => 'Active',
        'draft' => 'Draft',
        'archived' => 'Archived',
        'all' => 'All',
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

    'deletion' => [
        'button_label' => 'Delete permanently',
        'confirm_heading' => 'Permanently delete this variation?',
        'confirm_submit' => 'Delete permanently',
        'confirm_cancel' => 'Cancel',
        'impact_intro' => 'Deleting :sku (":product") permanently removes everything listed below. This cannot be undone.',
        'impact_attributes' => 'Attributes: :attributes',
        'impact_stock' => 'Stock on hand: :quantity',
        'impact_sale_lines' => 'Sale lines: :count (zero sale lines is what makes this variation deletable)',
        'impact_cart_lines' => 'Cart lines: :open in live baskets, :converted in baskets that already became orders',
        'impact_price_list_items' => 'Price list items: :count',
        'impact_cost_rows' => 'Cost rows: :count',
        'impact_media' => 'Media attached: :count (the image files themselves are not deleted)',
        'unsaved_edits_warning' => 'The page reloads from the database when the deletion completes — any unsaved changes elsewhere on this page are discarded.',
        'archive_instead' => 'Use "Archive variation" on this row instead: it stays in the catalog, keeps its SKU and history, and can be restored later.',
        'refusal' => [
            'has_history' => 'Variation ":sku" has :count sale line(s) and cannot be deleted. Archive it instead.',
            'has_stock' => 'Variation ":sku" still has :count in stock. Set stock to 0, or archive it instead.',
        ],
        'not_on_this_product' => 'Nothing was deleted: that variation does not belong to this product.',
        'field_confirm_label' => 'I understand this cannot be undone, and that this SKU and barcode become reusable',
        'field_sku_label' => 'Type the SKU (:sku) to confirm',
        'field_sku_mismatch' => 'The typed SKU does not match this variation\'s SKU.',
        'notification_success' => 'Variation :sku was permanently deleted.',
        'notification_not_confirmed' => 'Nothing was deleted: both the confirmation box and the exact SKU are required.',
        'notification_unauthorized' => 'You do not have permission to delete variations permanently.',

        // §3.19.8 B — the PRODUCT half of the same modal. The product-level
        // refusal keys live HERE rather than inside `refusal` above because
        // they are a different object with a different shape: a product
        // refusal names the product and, when variations block it, lists each
        // one through `blocked_variation` below (the variation's own words,
        // not a second vocabulary).
        'product_button_label' => 'Delete product permanently',
        'product_confirm_heading' => 'Permanently delete this product?',
        'product_confirm_submit' => 'Delete permanently',
        'archive_first_button' => 'Archive first',
        'archive_first_heading' => 'Only an archived product can be deleted',
        'archive_first_description' => 'Deleting is irreversible and frees the base SKU and slug, so it is offered only for an archived product. Archive ":name" first (its Status field), then come back here.',
        'archive_first_submit' => 'Open the product to archive it',
        'product_impact_intro' => 'Deleting :sku (":product") permanently removes the product, every variation listed below, and everything else listed. This cannot be undone.',
        'product_impact_identifiers' => 'Freed for reuse once it is gone: base SKU :base_sku and slug :slug.',
        'product_impact_variations' => 'Variations (:count):',
        'product_impact_variation' => ':sku — :attributes — :verdict',
        'product_impact_no_attributes' => 'no attributes',
        'product_impact_will_delete' => 'will be deleted',
        'product_impact_will_archive' => 'cannot be deleted (history or stock)',
        'product_impact_scopes' => 'Price-list scopes applying because of this product: :price_lists; promotion scopes: :promotions',
        'product_impact_price_list_items' => 'Price list items: :total (:variation on its variations, :product aimed at the product itself)',
        'product_impact_media' => 'Media attachments: :total (:variation on its variations, :product on the product itself — the files themselves are kept)',
        'product_impact_unrepeatable' => 'Unlike archiving, this is permanent: the variations, their SKUs and barcodes, stock rows, basket lines, price list items, costs and scopes are gone, and nothing brings them back.',
        'product_after_delete_note' => 'The deletion finishes back on the products list — this product no longer exists, so its own page goes with it.',
        'product_archive_instead' => 'Not sure? An archived product keeps all of that and can be restored — archiving is the reversible offer, this one is not.',
        'product_field_confirm_label' => 'I understand this cannot be undone, and that the base SKU and slug become reusable',
        'product_field_base_sku_label' => 'Type the base SKU (:base_sku) to confirm',
        'product_field_base_sku_mismatch' => 'The typed base SKU does not match this product\'s base SKU.',
        'product_notification_success' => 'Product :name was permanently deleted.',
        'product_notification_not_confirmed' => 'Nothing was deleted: both the confirmation box and the exact base SKU are required.',
        'product_notification_unauthorized' => 'You do not have permission to delete products permanently.',
        'product_refusal' => [
            'not_archived' => 'Only an archived product can be deleted. Archive :product first.',
            'blocked_by_variations' => '":product" cannot be deleted: :variations',
        ],
        'blocked_variation' => [
            'has_history' => 'variation ":sku" has :count sale line(s).',
            'has_stock' => 'variation ":sku" has :count in stock.',
        ],
    ],

    // §13.1 — the products list's three BULK actions and the confirmations they
    // demand (D3/D5/D6/D7). Its own top-level block: these are the LIST's words,
    // not the deletion flow's — where they report a product deletion refusal
    // they reuse `deletion.product_refusal.*`/`blocked_variation.*` through
    // App\Services\ProductDeletionRefusalMessage rather than restating them.
    'bulk' => [
        'archive_label' => 'Archive',
        'archive_heading' => 'Archive :count selected products?',
        'archive_description' => 'Each product is archived on its own; up to :limit products can be archived in one run. Products that are already archived are skipped and reported.',
        'archive_submit' => 'Archive',
        'publish_label' => 'Publish',
        'publish_heading' => 'Publish :count selected products?',
        'publish_description' => 'Each product is published on its own; up to :limit products can be published in one run. Products the catalog does not allow to publish are refused and reported.',
        'publish_submit' => 'Publish',
        'delete_label' => 'Delete permanently',
        'delete_heading' => 'Permanently delete the selected products?',
        'delete_over_limit' => 'You selected :selected products, but at most :limit can be deleted in one run.',
        'delete_over_limit_hint' => 'Select at most :limit products and try again.',
        'delete_impact_intro' => 'You selected :selected products: :deletable will be deleted permanently and :refused will be refused.',
        'delete_impact_refused_note' => 'A refused product is left exactly as it is — nothing about it changes.',
        'delete_impact_row' => ':name — :verdict',
        'delete_impact_will_delete' => 'will be deleted permanently',
        'delete_impact_unrepeatable' => 'This cannot be undone: the variations, their SKUs and barcodes, stock, basket lines and price list items go with the product. The image files themselves are kept.',
        'delete_impact_type_count' => 'To confirm, type the number of products that will be deleted (:count).',
        'field_confirm_label' => 'I understand this cannot be undone',
        'field_count_label' => 'Type the number of products to delete (:count)',
        'field_count_mismatch' => 'The typed number is not :count.',
        'limit_exceeded' => 'Nothing was changed: at most :limit products can be processed in one run.',
        'notification_unauthorized_status' => 'You do not have permission to change product status.',
        'notification_not_confirmed' => 'Nothing was deleted: the confirmation box and the exact number of products are both required.',
        'archive_done_title' => 'Products archived',
        'archive_done_body' => 'Archived: :archived. Already archived, skipped: :skipped. Failed: :failed.',
        'publish_done_title' => 'Products published',
        'publish_done_body' => 'Published: :published. Refused or failed: :failed.',
        'delete_done_title' => 'Products deleted',
        'delete_done_body' => 'Deleted: :deleted. Refused or failed: :failed.',
        'refusal_line' => '• :name — :reason',
        'reason' => [
            'already_archived' => 'it is already archived',
            'cannot_publish_empty_variable' => 'a variable product with no available variations cannot be published',
            'no_longer_exists' => 'it no longer exists',
            'failed' => 'it could not be changed (:detail)',
        ],
    ],

    // §3.19.8 C — the axis-restructuring flow. Its own top-level block rather
    // than more keys under `deletion`, because it is a different operation
    // with its own refusals: what it reports about variations reuses the
    // deletion wording it shares, and everything else here is its own.
    'axes_restructure' => [
        'button_label' => 'Change axes',
        'heading' => 'Change the variation axes',
        'description' => 'Adding or removing an axis or a value here reviews the impact first, then rewrites the axes and creates the new combinations. Unsaved edits elsewhere on this page are discarded when it finishes.',
        'submit' => 'Apply the axis change',
        'impact_intro' => 'Current axes: :current',
        'impact_new' => 'New axes: :new',
        'impact_unchanged_axes' => 'The axes are unchanged — confirming writes nothing at all.',
        'impact_no_variations' => 'No live variation is affected by this change.',
        'impact_will_delete' => 'Will be permanently deleted (:count):',
        'impact_will_archive' => 'Will be archived (:count):',
        'impact_will_become_unrestorable' => 'Will become unrestorable (:count):',
        'impact_no_attributes' => 'no attributes',
        'impact_delete_note' => 'A deleted variation and its SKU and barcode are gone for good — the identifiers become reusable.',
        'impact_archive_note' => 'An archived variation keeps its record, its SKU and its history, and stays off sale until it is restored.',
        'impact_no_delete_permission' => 'You do not have permission to delete variations permanently, so every one of these is archived instead — their SKUs stay occupied.',
        'impact_restorable_note' => 'These archived variations can be restored today. After this change their combination no longer fits the axes, so restoring them will be refused.',
        'impact_generated' => 'The combinations of the new axes are created afterwards as draft variations. A combination an archived variation already owns is restored with its own SKU instead.',
        'impact_unsaved_note' => 'The page reloads from the database when this completes.',
        'refusal' => [
            'invalid_axes' => 'Those axes cannot be declared for ":product": :detail',
            'plan_changed' => '":product" changed since you opened this dialog — reopen it to see the current impact. Nothing was changed.',
        ],
        'field_confirm_label' => 'I understand this cannot be undone, and that deleted SKUs and barcodes become reusable',
        'field_base_sku_label' => 'Type the base SKU (:base_sku) to confirm',
        'field_base_sku_mismatch' => 'The typed base SKU does not match this product\'s base SKU.',
        'notification_unauthorized' => 'You do not have permission to change this product\'s axes.',
        'notification_not_confirmed' => 'Nothing was done: both the confirmation box and the exact base SKU are required.',
        'notification_unchanged' => 'The axes are unchanged — nothing was written.',
        'notification_success_title' => 'Axes changed for :product',
        'notification_success_body' => ':deleted deleted, :archived archived, :created created, :restored restored.',
        'use_action_hint' => 'To make this change, use the "Change axes" action on the Axes tab: it lists exactly which variations must be deleted or archived first and carries the whole change out in one step.',
    ],

    'variations_generate' => [
        'button_label' => 'Generate missing variations',
        'confirm_heading' => 'Generate every missing combination?',
        'confirm_description' => 'This creates a new draft variation for every combination of the declared axis values that does not already exist. An archived combination whose axis values are still enabled is restored with its original SKU, not recreated — the same behavior as re-adding it manually.',
        'notification_title' => 'Variations generated',
        'notification_body' => ':created created, :restored restored.',
        'notification_unauthorized' => 'You do not have permission to generate variations for this product.',
    ],

    'new_variations' => [
        'section_label' => 'Add a variation',
        'sku_label' => 'SKU',
        'add_variation' => 'Add variation',
        'combination_help' => 'Choose one value for each declared axis to add exactly that combination.',
    ],

    'variation_restore' => [
        'section_label' => 'Archived variations',
        'section_label_with_count' => 'Archived variations (:count)',
        'button_label' => 'Restore',
        'confirm_heading' => 'Restore this variation?',
        'confirm_description' => 'Restoring ":label" brings it back as a draft, with its original SKU, barcode and history intact. It will not be visible or purchasable until you activate it again.',
        'confirm_submit' => 'Restore',
        'confirm_cancel' => 'Cancel',
        'notification_success' => 'Variation restored.',
        'notification_unauthorized' => 'You do not have permission to restore variations on this product.',
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
