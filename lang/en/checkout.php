<?php

/*
 * What the checkout endpoint (POST /api/checkout) answers when it refuses or when
 * the payment step needs attention (shipping stage 4c, shipping-domain-design.md
 * §9.1.5). Every sentence is fixed text: no exception message, no cart id, no
 * address id and no payment-method string typed by the customer is ever put into
 * a reply. The endpoint sets the STORE locale for the request (the `api` group
 * never applies it), exactly as the shipping quote does.
 *
 * Reason codes (`reason` in the JSON) are the contract; these sentences are the
 * words for a person.
 */
return [
    'phone_invalid' => 'The phone number is not valid. Check that no digit is missing.',
    'empty_cart' => 'Your cart is empty.',
    'cart_not_found' => 'We could not find that cart.',
    'address_not_found' => 'We could not find that address.',
    'unknown_payment_method' => 'This payment method is not available.',
    'promotion_no_longer_valid' => 'The promotion code is no longer valid. Review your cart and try again.',
    'insufficient_stock' => 'Some items are no longer available in the quantity you chose. Review your cart and try again.',
    'price_not_available' => 'A price in your cart is no longer available. Review your cart and try again.',
    'zero_total' => 'There is nothing to pay on this order, so it cannot be placed. Review your cart.',
    'currency_mismatch' => 'A price in your cart is not available in the shop\'s currency. Review your cart.',
    'checkout_state_changed' => 'Your cart changed while the order was being placed. Review it and try again.',
    'checkout_invalid' => 'We could not place the order with the details given. Check them and try again.',
    'checkout_failed' => 'We could not place your order. Your cart is kept. Try again in a moment, and contact the shop if it keeps failing.',
    'payment_needs_attention' => 'Your order is placed, but the payment step needs attention. Do not place it again; the shop will contact you.',
    'shipping_required' => 'Choose a delivery method before placing the order.',
    'shipping_invalid' => 'The delivery choice could not be read. Choose the delivery method again.',
    'shipping_method_unavailable' => 'That delivery method is not available for this order. Choose another one.',
    'shipping_quote_expired' => 'The delivery price has expired. Choose the delivery method again to see the current price.',
    'shipping_price_changed' => 'The delivery price has changed. Check the new price and confirm the order again.',
    'shipping_pickup_mismatch' => 'That delivery method does not match the delivery address (office or locker versus home address). Choose another method or address.',
    'terms_not_accepted' => 'Please accept the terms and conditions to place the order.',
    'terms_version_changed' => 'The terms and conditions changed while you were filling in the order. Please read them again and confirm.',
    'payment_method_unavailable' => 'That payment method is not available for this order. Choose another one.',
    // Deliberately neutral (cod-protection-design.md 4): it never says "blocked" and never which identifier matched.
    'payment_method_blocked' => 'This payment method is not available for this order. Please choose another one.',
    'marketing_consent_text' => "Subscribe me to the shop's newsletter with news and offers.",

    // The invoice request (order-context-design.md 9.3, 9.7, stage C2-A). `reason` is the contract; these are the words for a person.
    'invoice_not_supported' => 'This shop does not issue invoices through the checkout.',
    'invoice_details_invalid' => 'The invoice details are not valid. Check them and try again.',
    // :label is the name the merchant configured for the field in that country (or the generic one): the sentence quotes it.
    'invoice_field_required' => '“:label” is required for the invoice.',
    'invoice_field_format' => '“:label” is not in the expected format.',
    'invoice_individual_field' => 'A private person does not give “:label” on an invoice.',
    'invoice_labels' => [
        'tax_id' => 'Company ID / tax number',
        'registration_number' => 'Registration / VAT number',
        'representative' => 'Representative',
    ],
];
