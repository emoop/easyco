<?php

/* ACC-1: the answers of the customer-account API (security: every answer that could tell whether an address has an account is the same text). bg/en kept together. */
return [

    'register' => [
        'accepted' => 'Check your mailbox to activate the account.',
    ],

    'login' => [
        'invalid' => 'Invalid email or password.',
        'unverified' => 'Your email is not confirmed yet. Use the link from the mail or ask for a new one.',
    ],

    'verify' => [
        'invalid' => 'The link is invalid or has expired.',
        'done' => 'Your email is confirmed. You can log in now.',
        'resend_accepted' => 'If there is an unconfirmed account for this email, we sent a new link.',
    ],

    'password' => [
        'too_short' => 'The password must be at least 8 characters.',
        'too_long' => 'The password may be at most 72 bytes (a Cyrillic letter takes 2 bytes).',
        'same_as_email' => 'The password cannot be the same as the email.',
        'common' => 'This password is too common. Choose another one.',
        'forgot_accepted' => 'If there is an account for this email, we sent a link for a new password.',
        'reset_invalid' => 'The link for a new password is invalid or has expired.',
        'reset_done' => 'The password was changed.',
        'changed' => 'The password was changed. The other devices were signed out.',
        'current_wrong' => 'The current password is not correct.',
    ],

    'orders' => [
        'not_found' => 'Order not found.',
    ],

    'email' => [
        'accepted' => 'We sent a confirmation link to the new address. The email changes only after it is confirmed.',
        'confirm_invalid' => 'The link is invalid or has expired.',
        'confirmed' => 'The email was changed.',
    ],

];
