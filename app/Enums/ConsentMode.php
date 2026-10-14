<?php

namespace App\Enums;

/**
 * The installation consent regime, setting `privacy.consent_mode` (order-context-design.md 8.1). Default `strict`.
 *
 * STORED AS A PLAIN STRING COLUMN holding ->value, never a native DB enum (the convention of orders.status and order_events.type).
 * Every case has a label in lang/en/orders.php and lang/bg/orders.php, pinned by OrderContextEnumLabelsTest.
 */
enum ConsentMode: string
{
    case STRICT = 'strict';
    case NOTICE = 'notice';
    case OFF = 'off';
}
