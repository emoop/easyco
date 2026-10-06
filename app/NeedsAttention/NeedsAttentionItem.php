<?php

namespace App\NeedsAttention;

use EasyCo\Pricing\Money;

/**
 * One row of the "Needs attention" page (shipping-domain-design.md §7.2.20 §6): a FACT with an age,
 * never a judgement.
 *
 * THERE IS DELIBERATELY NO SEVERITY HERE, and no colour, no threshold, no rank and no "overdue"
 * flag — §7.2.7's own rule ("lists facts and enforces nothing") says the page may not invent a
 * priority of its own, so this object offers nothing for a later screen to tempt itself with. The
 * only ordering the page can express is the one the rows already carry: age, oldest first.
 *
 * A row is DERIVED, never stored: it has no id of its own, and it can only be as fresh as the read
 * that produced it (§6's own reasoning for why a stored `needs_attention` flag was refused — a flag
 * is a second copy of a fact, and a second copy drifts).
 *
 * $fact is a whole, translated sentence naming the channel or the figures it is about ("Refund by
 * cash recorded, not paid out yet"); the screen adds no words of its own to it. $amount is what the
 * fact is worth — the refund's own total for an owed refund, the signed difference for a transfer
 * that does not add up — and $ageDays/$startedOn are the same day counted two ways: whole calendar
 * days as of the store's today, and the store-local day the fact began.
 */
final class NeedsAttentionItem
{
    public function __construct(
        public readonly string $sourceKey,
        public readonly string $orderId,
        public readonly string $fact,
        public readonly Money $amount,
        public readonly int $ageDays,
        public readonly string $startedOn,
    ) {
    }
}
