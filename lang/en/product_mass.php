<?php

// P6d: «Bulk actions» on the product list.
return [
    'group' => 'Bulk actions',
    'submit' => 'Apply',
    'reset_submit' => 'Delete the manual prices',
    'clear_choice' => '— Clear —',
    'confirm' => 'The action will affect :count products.',
    'reset_warning' => 'The manual promo prices of ALL variations of :count products will be deleted and cannot be restored.',
    'unauthorized' => 'You are not allowed to do this.',
    'queued' => 'The task was accepted: :count products',
    'result' => 'Changed :changed, unchanged :unchanged.',
    'result_prices' => 'Deleted :prices manual prices of :changed products, unchanged :unchanged.',
    'result_skipped' => 'Skipped (no longer exist): :skipped.',

    'actions' => [
        'add_category' => 'Add a category',
        'remove_category' => 'Remove a category',
        'add_tag' => 'Add a tag',
        'remove_tag' => 'Remove a tag',
        'add_collection' => 'Add a collection',
        'remove_collection' => 'Remove a collection',
        'set_season' => 'Set or clear the season',
        'set_group' => 'Change the article group',
        'reset_manual_prices' => 'Return to regular prices',
    ],

    'fields' => [
        'add_category' => 'Category',
        'remove_category' => 'Category',
        'add_tag' => 'Tag',
        'remove_tag' => 'Tag',
        'add_collection' => 'Collection',
        'remove_collection' => 'Collection',
        'set_season' => 'Season',
        'set_group' => 'Article group',
    ],

    'hints' => [
        'add_category' => 'The category is added to the selected products; if they already have it, nothing changes.',
        'remove_category' => 'The category is removed from the selected products; if they do not have it, nothing changes.',
        'add_tag' => 'The tag is added to the selected products; if they already have it, nothing changes.',
        'remove_tag' => 'The tag is removed from the selected products; if they do not have it, nothing changes.',
        'add_collection' => 'The collection is added to the selected products; if they already have it, nothing changes.',
        'remove_collection' => 'The collection is removed from the selected products; if they do not have it, nothing changes.',
        'set_season' => 'A product has exactly one season. Choose «Clear» to remove the season.',
        'set_group' => 'The group the product is registered under in the cash register. A product has exactly one group.',
    ],

    'refused' => [
        'none' => 'No products are selected.',
        'unfiltered' => 'A bulk action on ALL products is not allowed. Pick the products by hand, or set a filter (or a search) and then choose «all matching».',
        'too_many' => 'Too many products for one operation (at most :limit). Narrow the filter.',
        'unknown_value' => 'The chosen value does not exist.',
        'field_off' => 'This field is switched off in the Catalog settings.',
        'group_required' => 'The article group is required and cannot be cleared.',
    ],
];
