<?php

namespace App\Storefront\Orders;

use Illuminate\Support\Facades\URL;

/**
 * The signed, temporary url of the order-received page (storefront-design.md §2.10, owner decision D1): the ONE place it is
 * built, used by the checkout response today and by the confirmation email later. The guest has no account, so the link IS
 * the key: it carries the order number, an expiry (30 days) and a signature made with the application key. It is absolute
 * (the email needs that) and is not a general "look up my order" feature: without the signature the page is a 404.
 */
final class OrderConfirmationUrl
{
    public const DAYS = 30;

    public function forOrder(string $orderNumber): string
    {
        return URL::temporarySignedRoute('storefront.order.received', now()->addDays(self::DAYS), ['orderNumber' => $orderNumber]);
    }
}
