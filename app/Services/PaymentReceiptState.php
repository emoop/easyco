<?php

namespace App\Services;

/**
 * The state of a bank-transfer payment against its receipts (shipping-domain-design.md §7.2.20 §3, §7).
 * PaymentReceiptStatus is the one source of the figures and of this state.
 *
 * NONE      no effective receipt;
 * PARTIAL   receipts that sum to LESS than the expected amount (the rest may still arrive);
 * MISMATCH  receipts that sum to MORE than the expected amount — and, defensively, a sum equal to it
 *           on a payment that is still unsettled (cannot happen: the recorder settles on equality in
 *           the same transaction; if it ever did, "unreconciled" is the truthful reading);
 * SETTLED   the payment holds the money (isSettled()), whatever the receipts say.
 */
enum PaymentReceiptState: string
{
    case NONE = 'none';
    case PARTIAL = 'partial';
    case MISMATCH = 'mismatch';
    case SETTLED = 'settled';
}
