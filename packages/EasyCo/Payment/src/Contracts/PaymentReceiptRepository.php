<?php

namespace EasyCo\Payment\Contracts;

use EasyCo\Payment\PaymentReceipt;
use EasyCo\Pricing\Money;

/**
 * The store of bank-transfer receipts (shipping-domain-design.md §7.2.20 §2). APPEND-ONLY: there is
 * no update and no delete. A correction is a new receipt that supersedes an old one; a receipt is
 * EFFECTIVE unless a later row names it in `supersedes_receipt_id`.
 *
 * It decides nothing and enforces no workflow rule (that is the receipt service's, R4a-2): no
 * limits, no day validation, no settlement.
 */
interface PaymentReceiptRepository
{
    /**
     * Appends a NEW receipt and assigns its id. A receipt that already has an id is refused
     * (LogicException): stored receipts are never written again.
     */
    public function save(PaymentReceipt $receipt): void;

    public function findById(string $id): ?PaymentReceipt;

    /**
     * The payment's EFFECTIVE receipts (not named by any later row's supersedes_receipt_id),
     * oldest first (by id).
     *
     * @return list<PaymentReceipt>
     */
    public function findEffectiveByPaymentId(string $paymentId): array;

    /**
     * The sum of the payment's effective receipts, in $currency — ONE grouped read; zero when there
     * are none.
     */
    public function effectiveSum(string $paymentId, string $currency): Money;

    /** Every row of the payment, superseded ones counted — the bound on a correction chain. */
    public function countRows(string $paymentId): int;
}
