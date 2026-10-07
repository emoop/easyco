<?php

/**
 * The "add X more for free shipping" sentence (shipping stage 3e,
 * shipping-domain-design.md §5.1). Translated in the STORE locale, not the
 * request's: the reader passes StoreLocale::current() to the translator
 * explicitly, because the `api` middleware group never applies the store locale.
 *
 * :amount is the remaining goods value, already formatted by
 * PriceDisplayFormatter (symbol and position included). :method is the merchant's
 * own shipping-method name — never customer input.
 *
 * The two locales carry the SAME placeholders (:amount, :method).
 */
return [
    'remaining' => 'Add :amount more for free shipping with “:method”.',
    'unlocked' => 'Shipping with “:method” is free.',
];
