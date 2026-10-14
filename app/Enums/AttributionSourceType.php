<?php

namespace App\Enums;

/**
 * Where an order came from (order-context-design.md 2.1.1). C2-A writes only `unknown`: the storefront is cookie-free, so no visit is captured yet.
 *
 * STORED AS A PLAIN STRING COLUMN holding ->value, never a native DB enum (the convention of orders.status and order_events.type).
 * Every case has a label in lang/en/orders.php and lang/bg/orders.php, pinned by OrderContextEnumLabelsTest.
 */
enum AttributionSourceType: string
{
    case UTM = 'utm';
    case ORGANIC = 'organic';
    case REFERRAL = 'referral';
    case DIRECT = 'direct';
    case AI_ASSISTANT = 'ai_assistant';
    case SOCIAL = 'social';
    case EMAIL = 'email';
    case ADMIN = 'admin';
    case AGENT = 'agent';
    case UNKNOWN = 'unknown';
}
