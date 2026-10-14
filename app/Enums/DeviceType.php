<?php

namespace App\Enums;

/**
 * The device class derived from the user agent by the server, never asserted by a client (order-context-design.md 2.1).
 *
 * STORED AS A PLAIN STRING COLUMN holding ->value, never a native DB enum (the convention of orders.status and order_events.type).
 * Every case has a label in lang/en/orders.php and lang/bg/orders.php, pinned by OrderContextEnumLabelsTest.
 */
enum DeviceType: string
{
    case DESKTOP = 'desktop';
    case MOBILE = 'mobile';
    case TABLET = 'tablet';
    case BOT = 'bot';
    case UNKNOWN = 'unknown';
}
