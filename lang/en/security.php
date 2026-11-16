<?php

return [
    'alarm' => [
        'subject' => 'Attention: many failed admin logins on :shop',
        'body' => 'The same admin account (reference :ref) had :count failed login attempts in the last :minutes minutes, from different addresses. Nothing was blocked. If this was not you, change your password and check access to the admin.',
    ],
    'page' => [
        'title' => 'Security',
        'navigation_label' => 'Security',
        'save_label' => 'Save',
        'saved_notification' => 'The setting is saved.',
        'bot_challenge_label' => 'Enhanced bot protection',
        'bot_challenge_hint' => 'When on, the server cache layer asks visitors for a check on the chosen pages (needs a cache layer in front of the site; by itself it changes nothing).',
        'bot_challenge_off' => 'Off',
        'bot_challenge_filters' => 'Filters and search only',
        'bot_challenge_misses' => 'Everything outside the cache',
    ],
];
