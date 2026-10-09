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
];
