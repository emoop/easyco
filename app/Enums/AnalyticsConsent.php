<?php

namespace App\Enums;

/**
 * The visitor analytics-consent state at placement (order-context-design.md 8.2); `unknown` is a real state, never inferred as `denied`.
 *
 * STORED AS A PLAIN STRING COLUMN holding ->value, never a native DB enum (the convention of orders.status and order_events.type).
 * Every case has a label in lang/en/orders.php and lang/bg/orders.php, pinned by OrderContextEnumLabelsTest.
 */
enum AnalyticsConsent: string
{
    case GRANTED = 'granted';
    case DENIED = 'denied';
    case UNKNOWN = 'unknown';
}
