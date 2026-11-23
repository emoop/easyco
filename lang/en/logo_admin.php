<?php

// LOGO-1: the tab «Logo» of «Settings». bg/en kept together; sentence case, no final period on labels. Never the word «brand» in our own keys.
return [
    'tab' => 'Logo',
    'fields' => [
        'name' => 'Shop name',
        'icon' => 'Site icon',
        'main' => 'Logo',
        'dark' => 'Dark logo for the admin',
    ],
    'hints' => [
        'name' => 'Shown in the title of every page, in the emails, in the admin and as the alternative text of the logo. 1 to 80 characters. Empty = the name from the server settings (APP_NAME).',
        'icon' => 'One square PNG or WebP picture, at least 512×512. It becomes the icon in the browser tab, on a phone home screen and in the admin. Until you add one, EasyCo\'s icon is used.',
        'main' => 'PNG or WebP, at least 200 px wide. Used in the emails to customers and in the admin (menu and login). Not used in the shop header. Until you add one, emails have no logo and the admin uses EasyCo\'s logo.',
        'dark' => 'Optional. For the admin\'s dark mode only: if your logo is dark, add a light one here. Empty = dark mode uses the main logo.',
    ],
    'empty_icon' => 'EasyCo\'s icon is used',
    'empty_logo' => 'No logo',
    'header_link' => 'The logo of the shop header is set in «Design»:',
    'header_link_label' => 'Open «Design»',
    'preview' => [
        'heading' => 'Preview',
        'tab' => 'Browser tab',
        'email' => 'Top of an email',
        'none' => 'No picture chosen',
    ],
    'saved' => 'Settings saved',
    'errors' => [
        'name' => 'The name is 1 to :max characters, plain text.',
        'missing' => 'The picture was not found in the media library.',
        'type' => 'Only :types are accepted.',
        'unreadable' => 'The picture cannot be read.',
        'no_gd' => 'This server cannot read :type pictures (GD support is missing).',
        'too_big_file' => 'The file is too big: at most :mb MB.',
        'too_many_pixels' => 'The picture is too large: :w×:h px. At most :mp million pixels.',
        'icon_square' => 'The icon must be square: :w×:h is not. Minimum :min×:min.',
        'icon_small' => 'The icon is too small: :w×:h. Minimum :min×:min.',
        'logo_small' => 'The logo is too narrow: :w px. Minimum width :min px.',
        'generate' => 'The picture could not be processed. Try another one.',
        'write' => 'The files could not be written. Check the permissions of the upload folder.',
    ],
];
