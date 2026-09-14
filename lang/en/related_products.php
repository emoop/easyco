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
