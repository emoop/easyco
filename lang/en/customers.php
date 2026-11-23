<?php

// ACC-4e-1: the admin «Клиенти» list and customer page (acc-4-gdpr-design.md D5, §7). bg/en kept together; sentence case, no final period on labels.
return [
    'navigation_label' => 'Customers',
    'title' => 'Customers',
    'search_placeholder' => 'Search by name, email or phone',
    'empty' => 'No customers',

    'columns' => [
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'orders' => 'Orders',
        'spent' => 'Total spent',
        'last_order' => 'Last order',
        'profile' => 'Account',
    ],

    'badges' => [
        'account' => 'Account',
        'guest' => 'Guest',
        'forgotten' => 'Forgotten',
    ],

    'tabs' => [
        'all' => 'All',
        'accounts' => 'With an account',
        'guests' => 'Guests only',
    ],

    'page' => [
        'back' => 'Back to customers',
        'unknown_name' => 'Customer',
        'privacy_unavailable' => 'Personal-data requests are not set up (PRIVACY_HASH_KEY is missing from the server settings). Contact whoever runs the server.',
    ],

    'sections' => [
        'data' => 'Details',
        'addresses' => 'Addresses',
        'orders' => 'Orders',
        'consents' => 'Consents',
        'lists' => 'Lists',
        'requests' => 'Requests for this email',
        'note' => 'Note',
        'cod' => 'Cash on delivery',
    ],

    'data' => [
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'account_since' => 'Account since',
        'spent' => 'Total spent',
    ],

    'addresses' => [
        'none' => 'No saved addresses',
        'default' => 'default',
    ],

    'orders' => [
        'number' => 'Number',
        'date' => 'Date',
        'status' => 'Status',
        'total' => 'Total',
        'count' => ':count order|:count orders',
        'latest' => 'the latest :shown are shown',
        'none' => 'No orders',
    ],

    'consents' => [
        'none' => 'No recorded consents',
        'order' => 'Order :id',
        'marketing_yes' => 'Marketing consent given',
        'marketing_no' => 'No marketing consent',
        'terms' => 'Terms accepted on :date (version :version)',
        'terms_no_version' => 'Terms accepted on :date',
    ],

    'note' => [
        'label' => 'Staff note',
        'hint' => 'Only staff see it. It is not part of the exported data, and it is deleted when the customer is forgotten.',
        'save' => 'Save',
        'saved' => 'Note saved',
        'invalid' => 'The note is at most 2000 characters and has no control characters.',
    ],

    'lists' => [
        'empty' => 'No lists. The mail and advertising modules will show them here.',
    ],

    'requests' => [
        'none' => 'No requests',
        'kind' => [
            'export' => 'Data export',
            'erase' => 'Data deletion',
        ],
        'status' => [
            'awaiting_confirmation' => 'Awaiting confirmation',
            'confirmed' => 'Confirmed',
            'processing' => 'Being prepared',
            'done' => 'Done',
            'expired' => 'Expired',
            'failed' => 'Failed',
        ],
        'asked' => 'asked :date',
        'by' => 'by :name',
        'download' => 'Download',
        'expires' => 'until :date',
        'resend' => 'Send again',
        'columns' => [
            'request' => 'Request',
            'status' => 'Status',
            'dates' => 'Dates',
            'staff' => 'Staff',
        ],
    ],

    'strip' => [
        'confirmed' => 'Confirmed request: :kind',
        'confirmed_detail' => 'confirmed on :time, asked by :name',
        'confirmed_detail_no_staff' => 'confirmed on :time',
        'awaiting' => 'Waiting for the customer to confirm (:kind), expires on :date',
    ],

    'actions' => [
        'export' => 'Download the data',
        'export_preparing' => 'The file is being prepared',
        'export_failed' => 'The request could not be created',
        'request_confirmation' => 'Request confirmation',
    ],

    'confirm' => [
        'heading' => 'Request confirmation',
        'kind' => 'What the customer asked for',
        'kind_export' => 'A copy of the data',
        'kind_erase' => 'Deletion of the data',
        'goes_to' => 'The mail goes to: :email',
        'send' => 'Send the mail',
        'sent' => 'The mail was sent',
        'already_heading' => 'Already sent',
        'already' => 'Already sent to :email. The link is valid until :date.',
        'resend' => 'Send again',
        'resent' => 'The mail was sent again',
        'mail_failed' => 'The mail could not be queued. The request is saved; try again with «Send again».',
        'not_resendable' => 'This request is no longer waiting for confirmation, or its time is over. Ask for a new one.',
        'invalid' => 'A mail cannot be sent to this address.',
    ],

    'cod' => [
        'none' => 'Not blocked',
        'until' => 'Blocked until :date',
        'forever' => 'Blocked permanently',
        'reason' => 'Reason: :reason',
        'order' => 'from order :id',
        'email' => 'Email',
        'phone' => 'Phone',
        'no_phones' => 'No phones',
        'no_key' => 'cannot be blocked',
        'refused' => 'This cannot be blocked.',
    ],
];
