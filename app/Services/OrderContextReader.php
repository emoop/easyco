<?php

namespace App\Services;

/**
 * THE ONE SOURCE of an order's context for the admin pages (order-context-design.md). The order-context data stages
 * are designed and not built, so this reader returns "nothing recorded" for every order and makes NO query; the
 * stages that build the data (the attribution row, the invoice-details row, the three columns on `orders`) replace
 * this method and nothing else — the order page reads only this class.
 */
class OrderContextReader
{
    public function forOrder(string $orderId): OrderContextView
    {
        return OrderContextView::notRecorded();
    }
}
