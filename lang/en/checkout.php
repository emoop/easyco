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
];
