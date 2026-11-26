<?php

/*
 * Courier strings (courier-integration-design.md §5, stage COUR-1a): the «Couriers» settings page. bg/en are kept together
 * (tests/Feature/Couriers/CouriersLangParityTest.php). Error codes are a CLOSED list: the page shows one of these texts for a
 * code, never what a courier answered.
 */
return [

    'page' => [
        'title' => 'Couriers',
        'navigation_label' => 'Couriers',
        'intro' => 'Each courier is an extension that stays switched off until you switch it on. A courier that is off costs nothing: no nightly download, no script in the checkout, no request to the courier. Offices and lockers are kept in our own database and refreshed every night.',
        'save_label' => 'Save',
        'saved_notification' => 'Courier settings saved.',
        'no_couriers' => 'No courier is installed.',
    ],

    'common' => [
        'enabled' => 'Enabled',
        'enabled_help' => 'Off by default. While it is off, the courier is never scheduled and never called.',
        'environment' => 'Environment',
        'environment_help' => 'Which set of credentials below is used. Test and production credentials are stored separately.',
        'env_test' => 'Test',
        'env_production' => 'Production',
        'map_enabled' => 'Map in the checkout',
        'map_enabled_help' => 'Stored now, used when the checkout picker is built.',
        'section_test' => 'Test environment',
        'section_production' => 'Production environment',
    ],

    'secret' => [
        'saved_placeholder' => 'Saved — leave empty to keep it',
        'help_none' => 'Not set. It is stored encrypted and is never shown again.',
        'help_saved' => 'A value is saved. Type a new one only to replace it.',
        'help_unreadable' => 'The saved value cannot be read (the application key changed). Enter it again.',
    ],

    'test' => [
        'button' => 'Check the connection',
        'help' => 'Uses the SAVED settings of the selected environment; save first. It makes one harmless call and shows only OK or a short error code.',
        'ok' => 'The connection works.',
        'failed' => 'The connection failed: :message (:code)',
    ],

    'errors' => [
        'not_enabled' => 'the courier is not enabled',
        'too_many_checks' => 'too many checks in a minute, wait a little',
        'not_configured' => 'the credentials are not filled in',
        'api_host_missing' => 'the API address is not set',
        'credentials_unreadable' => 'a saved secret cannot be read',
        'auth_failed' => 'the credentials were refused',
        'account_disabled' => 'the account is disabled',
        'not_found' => 'the address was not found',
        'rate_limited' => 'the courier asked us to slow down',
        'courier_unavailable' => 'the courier is not available',
        'http_error' => 'the courier answered with an error',
        'invalid_response' => 'the answer was not what was expected',
        'host_not_allowed' => 'the address is not allowed',
        'scheme_not_allowed' => 'the address is not allowed',
        'redirect_refused' => 'the courier redirected the request',
        'response_too_large' => 'the answer was too large',
        'timeout' => 'the courier did not answer in time',
        'connection_failed' => 'no connection to the courier',
        'invalid_request' => 'the request was refused',
        'unknown_error' => 'unknown error',
        'shrink' => 'the new list was much shorter, so nothing was changed',
        'unexpected_error' => 'unexpected error',
    ],

    'sync' => [
        'heading' => 'Points list',
        'button' => 'Update now',
        'queued' => 'The update of :courier is queued.',
        'throttled' => 'The list was updated a moment ago. Try again in a few minutes.',
        'not_enabled' => 'Enable the courier first.',
        'forbidden' => 'You may not start an update.',
        'never' => 'The list has not been updated yet. It is refreshed every night at 03:40.',
        'last' => 'Last update: :when — :outcome. Points seen :seen, new :new, changed :changed, deactivated :deactivated, skipped :skipped.',
        'last_error' => 'Reason: :message (:code)',
        'outcome' => [
            'ok' => 'done',
            'partial' => 'done, some items were skipped',
            'failed' => 'failed',
            'refused_shrink' => 'refused: the list was much shorter than before',
        ],
    ],

    'report' => [
        'heading' => 'Settlements and points',
        'line' => 'Settlements matched: :mapped. Not matched (own rows): :unmatched. Split in two or more: :splits. Active points: :total, without a settlement: :unmapped.',
        'unmatched_names' => 'Not matched: :names',
        'split_names' => 'Split: :names',
        'built_at' => 'Settlements last built: :when.',
        'never_built' => 'The settlements have not been built yet. They are built with the first update and rebuilt every Sunday at 04:00.',
        'no_settlements' => 'Points without a settlement of their own stay selectable under the name the courier gives them.',
    ],

    'econt' => [
        'username' => 'User name (Econt)',
        'password' => 'Password (Econt)',
    ],

    'speedy' => [
        'username' => 'User name (Speedy)',
        'password' => 'Password (Speedy)',
    ],

    'boxnow' => [
        'api_host' => 'API address',
        'api_host_help' => 'The host name BOX NOW gave you with your credentials, for example api-….boxnow.bg. Only a host on boxnow.bg or boxnow.gr is accepted.',
        'partner_id' => 'Partner ID (map)',
        'client_id' => 'Client ID',
        'client_secret' => 'Client secret',
    ],

    'validation' => [
        'api_host' => 'Enter a plain host name on boxnow.bg or boxnow.gr, without https:// or a path.',
        'secret_plain' => 'The value contains characters that are not allowed.',
    ],
];
