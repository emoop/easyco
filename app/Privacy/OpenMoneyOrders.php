<?php

namespace App\Privacy;

use App\NeedsAttention\NeedsAttentionSource;

/**
 * THE ORDERS WITH AN OPEN MONEY MATTER (ACC-4b): the orders that have an item in the admin's «needs attention» list: a refund that is OWED, a bank
 * transfer received and not reconciled (a mismatch), a payment step that never finished. The code has ONE reader of that question, the tagged
 * `NeedsAttentionSource`s (`needs_attention.sources`); this class only collects their order numbers, page after page, so a new source added to the list
 * is respected here without a line changed. An order in this set is never anonymised: the money is still being sorted out and its people are still needed.
 */
final class OpenMoneyOrders
{
    private const PER_PAGE = 500;

    /** @return list<string> the order ids, distinct */
    public function ids(): array
    {
        $ids = [];

        foreach (app()->tagged(NeedsAttentionSource::TAG) as $source) {
            /** @var NeedsAttentionSource $source */
            for ($page = 1; ; $page++) {
                $items = $source->page($page, self::PER_PAGE);

                foreach ($items as $item) {
                    $ids[(string) $item->orderId] = true;
                }

                if (count($items) < self::PER_PAGE) {
                    break;
                }
            }
        }

        return array_map('strval', array_keys($ids));
    }
}
