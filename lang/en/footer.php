<?php

// The footer builder (FT-1): the admin page "Footer", the validator's messages and the words the shop prints. bg and en change together.
return [

    'sections' => ['upper' => 'Upper level', 'lower' => 'Lower level'],

    'fields' => [
        'enabled' => 'Enabled',
        'padding' => 'Space inside',
        'bg' => 'Background colour',
        'text' => 'Text colour',
        'border_color' => 'Border colour (top)',
        'border_width' => 'Border thickness',
        'row' => 'Row',
        'column' => 'Column',
        'span' => 'Width',
        'align' => 'Alignment',
        'text_template' => 'Text',
        'text_hint' => '[year] = the current year (put in when the page is shown).',
        'lower_align' => 'Alignment of the line',
    ],

    'options' => [
        'compact' => 'Compact', 'normal' => 'Normal', 'spacious' => 'Spacious',
        'start' => 'Start', 'center' => 'Centre', 'end' => 'End',
        'span_1' => '1 share', 'span_2' => '2 shares',
        's' => 'Small', 'm' => 'Medium', 'l' => 'Large',
        'border_0' => 'None', 'border_1' => '1 px', 'border_2' => '2 px', 'border_3' => '3 px', 'border_4' => '4 px',
        'footer_location' => 'The menu of the Footer place',
    ],

    'settings' => [
        'menu' => 'Menu',
        'heading' => 'Heading',
        'html_block' => 'HTML block',
        'body' => 'Text',
        'placeholder' => 'Placeholder of the field',
        'button' => 'Button text',
        'consent' => 'Consent text',
        'items' => 'Networks',
        'network' => 'Network',
        'url' => 'Address',
        'methods' => 'Payment methods',
        'phone' => 'Phone',
        'email' => 'Email',
        'address' => 'Address',
        'hours' => 'Opening hours',
        'size' => 'Size',
    ],

    'blocks' => [
        'menu' => 'Menu',
        'html_block' => 'HTML block',
        'text' => 'Text',
        'newsletter' => 'Newsletter',
        'social' => 'Social networks',
        'payments' => 'Payment methods',
        'contacts' => 'Contacts',
        'spacer' => 'Space',
    ],

    'newsletter_note' => 'Shown on the site once newsletter subscription exists.',

    'notices' => [
        'low_contrast' => 'Low contrast between the text and the background of the :level (:ratio:1, at least 4.5:1 is advised). You can still save.',
        'level_upper' => 'upper level',
        'level_lower' => 'lower level',
    ],

    'payments' => [
        'visa' => 'Visa', 'mastercard' => 'Mastercard', 'maestro' => 'Maestro', 'apple_pay' => 'Apple Pay',
        'google_pay' => 'Google Pay', 'paypal' => 'PayPal', 'bank_transfer' => 'Bank transfer', 'cash_on_delivery' => 'Cash on delivery',
    ],

    // What the shop prints (a block left empty by the merchant falls back to these).
    'shop' => [
        'newsletter_heading' => 'Newsletter',
        'newsletter_email' => 'Your email',
        'newsletter_placeholder' => 'Your email',
        'newsletter_button' => 'Subscribe',
        'payments' => 'Payment methods',
    ],

    'errors' => [
        'unknown_key' => 'Unknown field: :key.',
        'unknown_setting' => 'Unknown setting: :key.',
        'bad_choice' => 'Not a valid choice.',
        'bad_list' => 'Not a valid list.',
        'bad_element' => 'Not a valid element.',
        'bad_color' => 'A colour like #1a2b3c.',
        'bad_width' => 'The thickness is from 0 to :max px.',
        'bad_url' => 'Enter a path in the shop (starts with "/") or an address with https://.',
        'bad_https_url' => 'The address must start with https://.',
        'bad_email' => 'Not a valid email address.',
        'required' => 'This field is required.',
        'too_long' => 'At most :max characters.',
        'not_plain' => 'Plain text only, without control characters.',
        'rows_count' => 'From 1 to :max rows.',
        'columns_count' => 'From 1 to :max columns in a row.',
        'too_many_in_column' => 'At most :max blocks in a column.',
        'too_many_blocks' => 'At most :max blocks in the footer.',
        'too_many_social' => 'At most :max networks.',
        'too_large' => 'The footer is too large to publish.',
    ],
];
