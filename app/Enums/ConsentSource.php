<?php

namespace App\Enums;

/**
 * How the analytics-consent answer arrived (order-context-design.md 8.2).
 *
 * STORED AS A PLAIN STRING COLUMN holding ->value, never a native DB enum (the convention of orders.status and order_events.type).
 * Every case has a label in lang/en/orders.php and lang/bg/orders.php, pinned by OrderContextEnumLabelsTest.
 */
enum ConsentSource: string
{
    case BANNER = 'banner';
    case API = 'api';
    case NOT_ASKED = 'not_asked';
}
