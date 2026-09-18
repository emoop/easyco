<?php

return [

    // Shared column labels for every drill-down page (Brand/Season/
    // Category/Tag/AttributeDefinition/AttributeValue) — genuinely
    // identical UI chrome across all six, not resource-specific
    // vocabulary, so this is the one deliberate exception to the
    // per-resource lang-file convention.
    'columns' => [
        'name' => 'Product',
        'base_sku' => 'Base SKU',
        'status' => 'Status',
        'axis_note' => 'Why it can\'t be unlinked here',
    ],

    'axis_note' => 'Used as a variation option — manage this product\'s variations directly.',

    // Tooltips on the linked product-count columns (Brand/Category/Season/
    // Tag/ProductGroup/AttributeDefinition/AttributeValue) — one shared
    // string per destination, not per resource. 'filtered_list' is for
    // links into ProductResource's own list (hides archived by default,
    // never shows VARIABLE); 'axis_list' is for the two axis_count
    // columns, whose own page shows every counted product, archived
    // included, so the filtered-list caveat would be wrong there.
    'count_tooltip' => [
        'filtered_list' => 'The count includes archived and VARIABLE products, which are not shown in this filtered list.',
        'axis_list' => 'The count includes archived products; the linked list shows all of them.',
    ],

    'bulk_unlink' => [
        'button' => 'Remove :entity from selected',
        'confirmation_heading' => 'Remove :entity from the selected products?',
        'detached' => '{0}No products were detached (already detached).|{1}:count of :total product detached.|[2,*]:count of :total products detached.',
        'queued' => ':count products queued for background detachment.',
    ],

    'delete_blocked' => [
        'single_count' => 'Cannot delete ":name" — it is still used by :count product(s). Detach it from every product first (see the linked count on the list).',
        'descriptive_only' => 'Cannot delete ":name" — it is used descriptively by :descriptive product(s). Remove all usage before deleting.',
        'axis_only' => 'Cannot delete ":name" — it is used as a variation option by :axis product(s). Remove all usage before deleting.',
        'descriptive_and_axis' => 'Cannot delete ":name" — it is used descriptively by :descriptive product(s) and as a variation option by :axis product(s). Remove all usage before deleting.',
    ],

];
