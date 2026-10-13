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
    // Stage 5g-2b: the same sentences with the KINDS of delivery the free shipping applies to, used only when those are NOT every kind
    // the method serves (otherwise the two sentences above are used, byte for byte). :kinds is built from the `kinds` words and `or`:
    // "an office or a locker", "an address, an office or a locker".
    'remaining_kinds' => 'Add :amount more for free shipping to :kinds with “:method”.',
    'unlocked_kinds' => 'Shipping to :kinds with “:method” is free.',
    'kinds' => [
        'address' => 'an address',
        'office' => 'an office',
        'locker' => 'a locker',
    ],
    'or' => 'or',
];
