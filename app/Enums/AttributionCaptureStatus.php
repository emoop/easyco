<?php

namespace App\Enums;

/**
 * Why an attribution row is what it is (order-context-design.md 2.1): `captured`, `skipped_no_consent`, `skipped_no_data`.
 *
 * STORED AS A PLAIN STRING COLUMN holding ->value, never a native DB enum (the convention of orders.status and order_events.type).
 * Every case has a label in lang/en/orders.php and lang/bg/orders.php, pinned by OrderContextEnumLabelsTest.
 */
enum AttributionCaptureStatus: string
{
    case CAPTURED = 'captured';
    case SKIPPED_NO_CONSENT = 'skipped_no_consent';
    case SKIPPED_NO_DATA = 'skipped_no_data';
}
