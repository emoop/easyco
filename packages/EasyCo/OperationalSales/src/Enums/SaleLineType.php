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

    // order-editing-design.md §4.1 — the full reversal of a SALE line
    // written by an order edit, never a customer return (REFUND is that).
    // Reuses REFUND's entire existing column set (§4.1). Written by
    // SaleLine::createEditReversal() (stage 3a), called only by
    // App\Services\OrderLineEditor — OrderLineEditor itself constructs no
    // line of this type.
    case EDIT_REVERSAL = 'edit_reversal';

    // Refunds R2a (shipping-domain-design.md §7.2.16) — the storno of ONE
    // REFUND line, appended when an OWED refund is cancelled: the ledger is
    // append-only and the source of truth for money refunded per product line,
    // so a cancelled refund is cancelled by a new row, never by deleting or
    // rewriting the REFUND line. Modelled on EDIT_REVERSAL; written only by
    // SaleLine::createRefundReversal(). It is NOT a return: the goods stay
    // returned, and every quantity sum filters on type = refund, so a storno
    // changes nothing about what counts as returned.
    case REFUND_REVERSAL = 'refund_reversal';
}
