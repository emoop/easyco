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
}
