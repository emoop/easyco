<?php

/*
 * Cash-on-delivery protection (cod-protection-design.md, stage C2-B): the blocks list, the block buttons on the order, and the "Matches" card.
 * A block stops cash on delivery only; the customer can still pay by bank transfer. The data is kept to prevent fraud and is never used for marketing.
 */
return [
    'navigation_label' => 'Order blocks',
    'model' => 'order block',
    'plural' => 'Order blocks',

    'type_options' => [
        'ip' => 'IP address',
        'phone' => 'Phone',
        'email' => 'Email',
    ],

    'columns' => [
        'type' => 'Type',
        'value' => 'Blocked value',
        'reason' => 'Reason',
        'order' => 'From order',
        'by' => 'By',
        'until' => 'Until',
        'status' => 'Status',
        'created' => 'Created',
    ],

    'status' => [
        'active' => 'Active',
        'expired' => 'Expired',
        'revoked' => 'Revoked',
    ],

    'permanent' => 'Permanent',
    'blocked_until' => 'Blocked until :date',
    'blocked_forever' => 'Blocked permanently',
    'not_blocked' => 'Not blocked',
    'ip_row_label' => 'Block by IP',
    'ip_row_hint' => 'The address itself is in the header of this page.',

    'actions' => [
        'block' => 'Block',
        'unblock' => 'Unblock',
        'extend' => 'Extend',
        'add' => 'Add a block',
        'block_heading' => 'Block this :type for cash on delivery',
        'unblock_heading' => 'Unblock this :type?',
        'unblock_description' => 'Cash on delivery becomes possible again for this :type at once.',
        'extend_heading' => 'Extend this block',
        'add_heading' => 'Block a phone, an email or an IP address',
    ],

    'form' => [
        'note' => 'Blocking stops only cash on delivery for this customer. Paying by bank transfer stays possible.',
        'duration' => 'For how long',
        'days_180' => '180 days',
        'forever' => 'Forever',
        'custom' => 'Another number of days',
        'custom_days' => 'Number of days',
        'reason' => 'Reason (optional)',
        'type' => 'What to block',
        'value' => 'The phone, the email or the IP address',
    ],

    'notice' => [
        'blocked' => 'Blocked',
        'extended' => 'Block extended',
        'unblocked' => 'Unblocked',
        'cannot_block' => 'That value cannot be blocked: a phone needs at least nine digits, an email and an IP address must be well formed.',
        'no_ip' => 'The IP address of this order is not available (it was not recorded, was anonymised, or you may not read it).',
    ],

    'filters' => [
        'type' => 'Type',
        'status' => 'Status',
    ],

    'unknown_staff' => 'Unknown',

    // The "Matches" card on the order view (design section 5).
    'matches' => [
        'section' => 'Matches',
        'none' => 'No other order shares the phone, the email or the delivery address or office.',
        'summary' => '{1} Matches :count other order (:refused cancelled or refunded)|[0,*] Matches :count other orders (:refused cancelled or refunded)',
        'weak_summary' => '{1} :count more with the same name only|[0,*] :count more with the same name only',
        'blocked_phone' => 'The phone is blocked until :date',
        'blocked_phone_forever' => 'The phone is blocked permanently',
        'blocked_email' => 'The email is blocked until :date',
        'blocked_email_forever' => 'The email is blocked permanently',
        'key_phone' => 'phone',
        'key_email' => 'email',
        'key_place' => 'address / office',
        'key_name' => 'name — possible match',
        'possible' => 'possible match',
        'order' => 'Order #:id',
        'more_than' => 'Showing the :limit most recent for each kind of match.',
    ],
];
