<?php

// "HTML blocks" (stage MENU-2): small reusable pieces of content, for example the picture next to a mega menu. bg and en change together.
return [
    'model' => 'HTML block',
    'plural' => 'HTML blocks',
    'navigation_label' => 'HTML blocks',
    'title' => 'HTML blocks',

    'fields' => [
        'title' => 'Title (for you only)',
        'body' => 'Content',
        'pictures' => 'Pictures with links',
        'picture' => 'Picture',
        'alt' => 'Picture description (alt)',
        'url' => 'Link when clicked (optional)',
        'updated_at' => 'Changed',
    ],

    'help' => [
        'pictures' => 'A link on text is set with the "link" button in the editor; a link on a picture, here.',
        'body' => 'Headings, bold and italic, lists, a link and a picture. Scripts, styles and embedded frames are not kept.',
    ],

    'actions' => [
        'create' => 'New block',
        'edit' => 'Edit',
        'add_picture' => 'Add a picture',
        'delete' => 'Delete',
    ],

    'notice' => ['saved' => 'The block was saved.'],

    // HB-1: «Block style» — the block as a styled box (the values become rules of the token stylesheet, never inline styles on the shop)
    'style' => [
        'title' => 'Block style',
        'description' => 'Make the block a coloured box (for example «Autumn -10% promo code» on a green background). Empty fields change nothing; with no value at all the block is as it always was.',
        'sample' => 'Sample text',
        'none' => 'none',
        'clear' => 'No style',
        'contrast_warning' => 'Low contrast (:ratio:1): the text may be hard to read. At least 4.5:1 is recommended. You can save it anyway.',
        'fields' => [
            'bg' => 'Background', 'color' => 'Text colour', 'pad_v' => 'Vertical padding (px)', 'pad_h' => 'Horizontal padding (px)', 'border_w' => 'Border width (px)',
            'border_color' => 'Border colour', 'radius' => 'Corner radius (px)', 'width_mode' => 'Width', 'width_px' => 'Width in px', 'align' => 'Alignment', 'size' => 'Text size',
        ],
        'hints' => [
            'bg' => 'A colour like #1a2b3c. «none» = no background.',
            'color' => 'A colour like #1a2b3c. «none» = the colour of the place.',
            'pad_v' => 'Top and bottom, 0 to 64 (default 0).',
            'pad_h' => 'Left and right, 0 to 64 (default 0).',
            'border_w' => '0 to 4 (default 0: no border).',
            'border_color' => 'A colour like #1a2b3c. «none» = the text colour.',
            'radius' => '0 to 32 (default 0).',
            'width_mode' => 'By default as wide as the content, never wider than the column.',
            'width_px' => '100 to 800 (required for «Fixed»).',
            'align' => 'Left by default.',
            'size' => 'As the place by default.',
        ],
        'options' => [
            'width_mode' => ['auto' => 'As wide as the content', 'full' => 'Full width', 'fixed' => 'Fixed'],
            'align' => ['left' => 'Left', 'center' => 'Centre', 'right' => 'Right'],
            'size' => ['small' => 'Small', 'normal' => 'Normal', 'large' => 'Large'],
        ],
        'errors' => [
            'bad_style' => 'Invalid style.',
            'unknown_key' => 'Unknown field: :key.',
            'bad_color' => 'A colour like #1a2b3c.',
            'bad_number' => 'A whole number from 0 to :max.',
            'bad_choice' => 'Invalid choice.',
            'width_required' => 'With a fixed width enter a width from :min to :max px.',
            'bad_width' => 'A width from :min to :max px.',
            'too_many' => 'At most :max blocks can have a style. Remove the style of another block.',
        ],
    ],
    // SC-1.1: the places that show a block, named in the refusal when the right column is one of them
    'places' => [
        'menu' => 'the menu :menus',
        'footer' => 'the footer',
        'summary' => 'the right column',
        'join' => ' and ',
    ],

    'errors' => [
        'too_many' => 'At most :max pictures.',
        'picture_missing' => 'Choose a picture.',
        'alt' => 'The description is at most :max characters, plain text.',
        'url' => 'The link must be an https:// address or a path in the shop (starts with "/", for example /product-category/boys). javascript:, data:, http: and //address are not allowed.',
        'in_use' => 'The block ":title" is shown by the menu :menus and cannot be deleted.',
        'in_use_footer' => 'The block ":title" is shown by the footer and cannot be deleted.',
        'in_use_both' => 'The block ":title" is shown by the menu :menus and by the footer and cannot be deleted.',
        'in_use_places' => 'The block ":title" is shown by :places and cannot be deleted.',
    ],
];
