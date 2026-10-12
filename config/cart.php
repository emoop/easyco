<?php

return [

    /*
     * How many days the guest cart cookie (`easyco_cart`, App\Http\Middleware\PersistGuestCartToken) may live. The cookie
     * mirrors the session's server-generated cart token so a guest's cart survives the 2-hour session; it is NEVER kept
     * longer than the live cart row itself (guest carts expire 10 days after their last write, CartController::expiry()),
     * so a value above 10 changes nothing. Default 7.
     */
    'guest_cookie_days' => (int) env('CART_GUEST_COOKIE_DAYS', 7),
];
