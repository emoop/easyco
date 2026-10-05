<?php

namespace App\Services\Exceptions;

use EasyCo\Pricing\Money;
use RuntimeException;

/**
 * A bank transfer was RECEIVED for this order and has not been reconciled (shipping-domain-design.md
 * §7.2.20 §3): at least one effective receipt sits on a bank-transfer payment that is not settled.
 * The money is in hand, so an operation that would void or reissue the pending payment — cancel,
 * a return, an order edit — or settle it through the old one-click "mark as received" is refused by
 * name: a cancel would void the payment and orphan the money; the one-click confirmation would settle
 * for the full expected amount over a receipt that does not match it.
 *
 * Thrown inside the order-locked transaction before anything is written. The message is a translated
 * sentence (orders.payment_receipt.unreconciled) naming the amount received so far.
 */
final class PaymentReceiptUnreconciledException extends RuntimeException
{
    public function __construct(private readonly Money $received)
    {
        parent::__construct(__('orders.payment_receipt.unreconciled', [
            'received' => $received->decimalValue().' '.$received->currency()->code(),
        ]));
    }

    /** The sum of the payment's effective receipts. */
    public function received(): Money
    {
        return $this->received;
    }
}
