<?php

namespace EasyCo\Pricing\Seeders;

use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\PriceList;
use Illuminate\Database\Seeder;

/**
 * Seeds the two reserved, always-existing system PriceLists — "Regular
 * Prices" and "Manual Sale" — every store must have exactly once, per
 * pricing-persistence-domain-design.md §4.5/§8 item 3.
 *
 * Idempotent by design: findSystemListByName() is checked before each
 * create, so re-running this seeder (e.g. on every deploy) never
 * produces a duplicate.
 *
 * Priority reasoning:
 * - "Regular Prices" = 0 — never actually competes for priority in
 *   practice (§4.6 always resolves it by name, not by winning the
 *   priority race), so the value itself carries no functional meaning;
 *   0 is simply the lowest, most legible default.
 * - "Manual Sale" = 1000 — a deliberate choice, not arbitrary: high
 *   enough to sit above every typical merchant-campaign priority seen
 *   in this domain's tests/examples so far (5-100), so a merchant's
 *   plain "Sale Price" field wins by default, but not artificially
 *   unbeatable — a merchant can still deliberately give a specific
 *   campaign a priority above 1000 to have it override the manual sale
 *   price (§4.5).
 */
class PricingSystemListsSeeder extends Seeder
{
    public function run(PriceListRepository $priceListRepository): void
    {
        $this->seedIfMissing($priceListRepository, 'Regular Prices', PriceListMode::FIXED_ITEMS, 0);
        $this->seedIfMissing($priceListRepository, 'Manual Sale', PriceListMode::FIXED_ITEMS, 1000);
    }

    private function seedIfMissing(
        PriceListRepository $priceListRepository,
        string $name,
        PriceListMode $mode,
        int $priority,
    ): void {
        if ($priceListRepository->findSystemListByName($name) !== null) {
            return;
        }

        $priceListRepository->save(PriceList::createSystemList($name, $mode, priority: $priority));
    }
}
