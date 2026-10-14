<?php

namespace App\Enums;

/**
 * Who an invoice is for (order-context-design.md 2.2): a company (with a tax id) or an individual (never a tax id).
 *
 * STORED AS A PLAIN STRING COLUMN holding ->value, never a native DB enum (the convention of orders.status and order_events.type).
 * Every case has a label in lang/en/orders.php and lang/bg/orders.php, pinned by OrderContextEnumLabelsTest.
 */
enum InvoiceRecipientType: string
{
    case COMPANY = 'company';
    case INDIVIDUAL = 'individual';
}
