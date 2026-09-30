<?php

namespace EasyCo\OperationalSales\Enums;

/**
 * See operational-sales-domain-design.md §2/§4 for the full status
 * taxonomy this maps from (the source system's `stats` column values).
 */
enum SaleLineType: string
{
    case SALE = 'sale';
    case RESERVATION = 'reservation';
    case REFUND = 'refund';
    case SHIPPING = 'shipping';
    case INSTALLMENT_PAYMENT = 'installment_payment';

    // order-editing-design.md §4.1 — a full/partial reversal of a SALE line
    // written by an order edit, never a customer return (REFUND is that).
    // Reuses REFUND's entire existing column set (§4.1); unused until
    // stage 3's OrderEditor writes one.
    case EDIT_REVERSAL = 'edit_reversal';
}
