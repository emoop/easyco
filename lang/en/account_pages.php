<?php

/* ACC-3a: the storefront account pages (login, register, forgot / reset password, verify, email confirm, /account). bg/en kept together. `js` goes into data attributes of the page (no inline script). */
return [
    'title' => 'My profile',

    'login_title' => 'Sign in',
    'register_title' => 'Create an account',
    'forgot_title' => 'Forgot password',
    'reset_title' => 'New password',
    'verify_title' => 'Confirm your email',
    'confirm_title' => 'Confirm your new email',

    'email' => 'Email',
    'password' => 'Password',
    'new_password' => 'New password',
    'new_password_repeat' => 'Repeat the new password',
    'show_password' => 'Show',
    'hide_password' => 'Hide',
    'remember' => 'Remember me',
    'password_hint' => '8 to 72 characters. Not the same as your email and not a common password.',

    'login_submit' => 'Sign in',
    'register_submit' => 'Create account',
    'forgot_submit' => 'Send link',
    'reset_submit' => 'Change password',
    'verify_button' => 'Confirm email',
    'confirm_button' => 'Confirm new email',
    'resend_button' => 'Send the email again',

    'forgot_link' => 'Forgot password?',
    'register_link' => "Don't have an account? Register",
    'login_link' => 'Already have an account? Sign in',
    'login_only_link' => 'Sign in',
    'forgot_again_link' => 'Forgot password',
    'back_to_account_link' => 'Try again from your profile',
    'forgot_intro' => 'Enter your email and we will send you a link for a new password.',
    'verify_intro' => 'Press the button to confirm your email.',
    'confirm_intro' => 'Press the button to confirm your new email.',
    'noscript' => 'This page needs JavaScript. Turn it on in your browser and try again.',

    'notice_password_reset' => 'The password was changed. Sign in with the new password.',

    'home_greeting' => 'Hello, :email',
    'logout' => 'Sign out',

    'js' => [
        'sending' => 'One moment…',
        'login_invalid' => 'Wrong email or password.',
        'login_unverified' => 'Confirm your email first. The email with the link is waiting in your inbox.',
        'resend_done' => 'If an account for this email is waiting for confirmation, we sent a new email.',
        'too_many' => 'Too many attempts, try again in :minutes min.',
        'too_many_soon' => 'Too many attempts, try again in a moment.',
        'generic_error' => 'Something went wrong. Try again in a moment.',
        'register_done' => 'We sent an email to :email. Open it to confirm your account.',
        'forgot_done' => 'If there is an account with this email, we sent it a link for a new password.',
        'password_mismatch' => 'The passwords do not match.',
        'reset_invalid' => 'The link is invalid or has expired.',
        'verify_done' => 'The email is confirmed. You can sign in now.',
        'verify_invalid' => 'The link is invalid or has expired.',
        'confirm_done' => 'The new email is confirmed. Sign in with it.',
        'confirm_invalid' => 'The link is invalid or has expired.',
        'email_required' => 'Enter an email.',
        'password_required' => 'Enter a password.',
    ],
];
