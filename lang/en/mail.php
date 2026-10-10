<?php

/*
 * Mail strings (mail-design.md, stage M1): the labels of the blocks inside the order-confirmation mail
 * and the "Payment: bank transfer" settings page. The wording of the mail itself lives in
 * resources/mail/{locale}/*.md. bg/en are kept together (tests/Feature/Mail/MailLangParityTest.php).
 */
return [

    'order' => [
        'lines' => [
            'product' => 'Product',
            'quantity' => 'Qty',
            'unit_price' => 'Price',
            'line_total' => 'Total',
            'sku' => 'SKU',
        ],
        'totals' => [
            'subtotal' => 'Subtotal',
            'discount' => 'Discount',
            'discount_with_code' => 'Discount (:code)',
            'shipping' => 'Shipping',
            'shipping_named' => 'Shipping (:method)',
            'shipping_free' => 'Free',
            'total' => 'Total',
        ],
        'delivery' => [
            'heading' => 'Delivery',
            'method' => 'Delivery method',
            'recipient' => 'Recipient',
            'address' => 'Address',
            'pickup_point' => 'Pick-up point',
            'courier' => 'Courier',
            'reference' => 'Point code',
        ],
    ],

    'payment' => [
        'methods' => [
            'bank_transfer' => 'Bank transfer',
            'cash_on_delivery' => 'Cash on delivery',
        ],
        'unknown' => 'To be confirmed',
        'cash_on_delivery_due' => 'Amount to pay on delivery: :amount',
    ],

    'bank_transfer' => [
        'heading' => 'Pay by bank transfer',
        'holder' => 'Account holder',
        'bank' => 'Bank',
        'iban' => 'IBAN',
        'bic' => 'BIC',
        'reason' => 'Reason for payment',
        'reason_value' => 'Order :number',
        'amount' => 'Amount',
        'deadline' => 'Please pay within :days day(s) of the order date.',
        'iban_invalid' => 'Enter a valid IBAN, for example BG80BNBG96611020345678.',
        'bic_invalid' => 'Enter a valid BIC of 8 or 11 characters, for example BNBGBGSD.',
    ],

    'bank_transfer_page' => [
        'navigation_label' => 'Payment: bank transfer',
        'title' => 'Payment: bank transfer',
        'help' => 'These details are shown in the order confirmation email for orders paid by bank transfer. While both the IBAN and the instructions are empty, the confirmation email will not contain payment details.',
        'account_holder' => 'Account holder',
        'bank_name' => 'Bank name',
        'iban' => 'IBAN',
        'iban_help' => 'Spaces are ignored. The number is checked for a valid format.',
        'bic' => 'BIC / SWIFT',
        'deadline_days' => 'Payment deadline (days)',
        'deadline_help' => 'Optional, 1 to 60. Leave empty for no deadline sentence.',
        'instructions' => 'Instructions',
        'instructions_help' => 'Free text shown below the details, up to 1000 characters. Plain text only.',
        'save_label' => 'Save',
        'saved_notification' => 'Payment details saved',
    ],

    // Stage M2: the "Mail" settings page (transport, sender identities, test email, DNS check, queue health).
    'page' => [
        'navigation_label' => 'Mail',
        'title' => 'Mail',
        'intro' => 'How the shop sends email: order confirmations and, later, other messages. Nothing on this page can stop a customer from ordering.',
        'section_status' => 'Current state',
        'section_transport' => 'How mail is sent',
        'section_sender' => 'Sender',
        'section_test' => 'Test email',
        'section_dns' => 'Check DNS',
        'section_queue' => 'Sending queue',
        'save_label' => 'Save',
        'saved_notification' => 'Mail settings saved',
    ],

    'transport' => [
        'label' => 'Mail service',
        'help' => 'Choose "Server setting" to keep what the server administrator configured. Choose SMTP to type the details your mail provider gave you.',
        'env' => 'Server setting (.env)',
        'smtp' => 'SMTP',
    ],

    'smtp' => [
        'host' => 'SMTP server',
        'host_help' => 'For example smtp-relay.example.com. Letters, digits, dots and hyphens only.',
        'port' => 'Port',
        'encryption' => 'Encryption',
        'encryption_tls' => 'TLS (STARTTLS, usually port 587)',
        'encryption_ssl' => 'SSL (usually port 465)',
        'encryption_none' => 'None (not recommended)',
        'username' => 'Username',
        'password' => 'Password',
        'password_saved' => '•••••••• (saved)',
        'password_help_saved' => 'A password is saved and is never shown. Leave this empty to keep it, or type a new one to replace it.',
        'password_help_none' => 'The password is stored encrypted and is never shown again after saving.',
        'password_help_unreadable' => 'The saved password can no longer be read (the application key changed). Re-enter the password and save.',
        'remove_password' => 'Remove saved password',
        'remove_password_confirm' => 'The saved password will be deleted. Sending through SMTP will fail until you enter a new one.',
        'password_removed' => 'The saved password was removed',
    ],

    'sender' => [
        'help' => 'Customers see these addresses in their inbox. Use an address on your own domain, not a free one such as gmail. Orders and payments use the first pair; reminders and newsletters will use the second.',
        'transactional_address' => 'Orders: sender address',
        'transactional_name' => 'Orders: sender name',
        'marketing_address' => 'News: sender address',
        'marketing_name' => 'News: sender name',
        'reply_to' => 'Reply-to address',
        'reply_to_help' => 'Where customer replies go, for example info@yourshop.com. Optional.',
        'domain_confirmed' => 'I own this domain and have set up its DNS records',
        'domain_confirmed_help' => 'Tick this once SPF and DKIM are set up at your mail provider. Until then the page shows "sender not verified".',
    ],

    'status' => [
        'log' => 'Emails are only written to the log. Nothing is sent to customers.',
        'array' => 'Emails are collected in memory and not sent (test mode).',
        'env_mailer' => 'Emails are sent through the server setting (:mailer).',
        'smtp' => 'SMTP configured: :host, port :port.',
        'sender_unverified' => 'Sender not verified. Tick the confirmation below once the DNS records of your domain are set up.',
        'sender_verified' => 'Sender confirmed by you.',
        'password_unreadable' => 'The saved SMTP password cannot be read. Re-enter it below.',
    ],

    'test' => [
        'recipient' => 'Send the test to',
        'recipient_help' => 'Your own address by default. Another address is allowed once per minute.',
        'button' => 'Send test email',
        'success' => 'A test email was handed to the mail server for :to. Check that inbox.',
        'failed' => 'The test email could not be sent: :error',
        'rate_limited' => 'Please wait :seconds seconds before sending a test to another address.',
        'invalid_address' => 'Enter a single valid email address.',
    ],

    'test_email' => [
        'subject' => 'Test email from your shop',
        'body' => 'This is a test email. If you can read it, your shop can send email with the current settings.',
    ],

    'dns' => [
        'help' => 'An advisory look at the DNS records of your sender domain. It never blocks sending, and "not seen yet" only means it was not visible from here right now.',
        'include' => 'Provider include (optional)',
        'include_help' => 'The name your provider asks you to add to SPF, for example spf.provider.com.',
        'selector' => 'DKIM selector (optional)',
        'selector_help' => 'The first part of the DKIM name your provider gave you, for example s1 or mail.',
        'button' => 'Check DNS',
        'domain_heading' => 'Domain :domain',
        'no_domain' => 'Enter a valid sender address first, then check its domain.',
        'error' => 'The check could not run right now. Try again later.',
        'invalid' => 'This is not a valid host name, so nothing was looked up.',
        'skipped' => 'Not checked: the time for this check ran out. Try again.',
        'spf_ok' => 'SPF record found.',
        'spf_unseen' => 'SPF record not seen yet. Your provider tells you which TXT record to add on the domain.',
        'spf_include_ok' => 'The provider include is in the SPF record.',
        'spf_include_unseen' => 'The provider include is not seen yet in the SPF record. Check the exact name with your provider.',
        'dmarc_ok' => 'DMARC record found.',
        'dmarc_unseen' => 'DMARC record not seen yet. A start such as p=none is fine; see the help page.',
        'dkim_ok' => 'DKIM record found.',
        'dkim_unseen' => 'DKIM record not seen yet. DNS changes can take a few hours to appear.',
    ],

    'queue' => [
        'not_available' => 'Queue status is not available (the queue does not use the database).',
        'line' => ':queue: :count waiting, oldest :age.',
        'line_empty' => ':queue: nothing waiting.',
        'name_transactional' => 'Order emails',
        'name_marketing' => 'News emails',
        'worker_hint' => 'If emails wait for a long time, the background worker is not running or does not serve the mail queues. See the help page.',
    ],

    'validation' => [
        'address' => 'Enter a single valid email address, without names, commas or line breaks.',
        'name_plain' => 'Use plain text only, without line breaks or invisible control characters.',
        'name_length' => 'The name may be at most 80 characters.',
        'password_plain' => 'The password may not contain line breaks or control characters.',
    ],
];
