<?php

// The admin page "Appearance" (stage D1): colours, fonts, corners and the top banner of the storefront. bg and en change together.
return [
    'title' => 'Appearance',
    'navigation_label' => 'Appearance',
    'save_label' => 'Save',
    'saved_notification' => 'Appearance saved',
    'counter' => ':count / :max',
    'contrast_warning' => 'Low contrast (:ratio:1): the text may be hard to read. At least 4.5:1 is recommended. You can still save.',
    'banner_moved' => 'The top banner is edited in Design.',

    'sections' => [
        'colors' => 'Colours',
        'colors_help' => 'The colours of the shop. Only a colour you change is stored; the others stay standard.',
        'fonts' => 'Fonts and shapes',
        'fonts_help' => 'The fonts come with the shop (nothing is loaded from another site). The shape decides how round the corners are.',
    ],

    'colors' => [
        'background' => 'Page background',
        'text' => 'Text',
        'muted' => 'Muted text',
        'primary' => 'Primary (buttons)',
        'on_primary' => 'Text on the primary colour',
        'accent' => 'Accent (sale price)',
        'border' => 'Borders',
        'header_bg' => 'Header: background',
        'header_text' => 'Header: text',
        'footer_bg' => 'Footer: background',
        'footer_text' => 'Footer: text',
        'banner_text' => 'Banner: text colour',
        'banner_bg' => 'Banner: background',
    ],

    'fields' => [
        'font_body' => 'Body font',
        'font_heading' => 'Heading font',
        'radius' => 'Corner radius',
        'button' => 'Button style',
        'banner_enabled' => 'Show the banner',
        'banner_text' => 'Text',
        'banner_url' => 'Link (optional)',
        'banner_url_help' => 'A path in the shop (starts with "/") or an address that starts with https://.',
        'banner_link_label' => 'Link text (optional)',
        'banner_link_label_help' => 'When empty and there is a link, the whole banner is the link.',
        'banner_starts_at' => 'From (optional)',
        'banner_ends_at' => 'Until (optional)',
    ],

    'fonts' => ['same' => 'Same as the body font'],

    'radii' => [
        'none' => 'No rounding',
        'sm' => 'Small',
        'md' => 'Medium (standard)',
        'lg' => 'Large',
        'pill' => 'Pill-shaped buttons',
    ],

    'buttons' => [
        'solid' => 'Solid',
        'outline' => 'Outline only',
    ],

    // DM-1: «Dark mode» (dark-mode.md). Shown only while the STOREFRONT_DARK_MODE flag is on.
    'dark' => [
        'section' => 'Dark mode',
        'section_help' => 'Black page, white text. The switch is site-wide, for every visitor. Your light colours are kept and come back when you switch it off.',
        'mode' => 'Dark mode of the site',
        'mode_help' => 'For all visitors. The colours of zones you set in «Design» (header, footer, buy button, …) stay exactly as you set them.',
        'button' => 'Button colour (dark mode)',
        'button_help' => 'Dark mode only; it is not the light «Primary colour». The text colour inside the buttons is computed automatically.',
        'preset_orange' => 'Orange',
        'preset_white' => 'White',
        'summary' => 'Text on the button: :text. Button / black page contrast: :button:1 (at least :button_min:1 needed). Text / button contrast: :text_ratio:1 (at least :text_min:1 needed).',
        'no_logo' => 'No dark logo is uploaded — the logo may not be visible on the black background.',
        'text_white' => 'white',
        'text_black' => 'black',
    ],

    'errors' => [
        'dark_button_format' => 'The button colour must look like #RRGGBB.',
        'dark_button' => 'This colour is not visible enough on the black page (:ratio:1, at least :min:1 needed). Pick a lighter colour.',
        'dark_button_text' => 'The text on the button would not be readable (:ratio:1, at least :min:1 needed). Pick another colour.',
        'color' => 'The colour must look like #RRGGBB.',
        'url' => 'Enter a path in the shop (starts with "/") or an address with https://.',
        'ends_before_starts' => 'The end date must be after the start date.',
    ],
];
