<?php

namespace App\Console\Commands;

use App\Services\PriceSort\SortPriceRefreshDispatcher;
use Carbon\CarbonImmutable;
use EasyCo\Pricing\Persistence\Eloquent\PriceListModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * P6a, the TIME BOUNDARIES: a price list with a `valid_from` / `valid_until` changes prices at a known moment, with no write to react to. The scheduler runs this EVERY MINUTE; it is cheap on purpose:
 *
 *  - it makes ONE query on the price lists table: «is there an ACTIVE list whose valid_from or valid_until lies in the window (last processed moment, now]?». With none, it stores `now` and exits;
 *  - otherwise it refreshes only the products those lists can touch (ALL products only for a list with no scope), through the same queued, unique jobs the fast path uses;
 *  - a list without any date never matches (a NULL is in no window): it takes effect when it is saved or switched on, and that write is the event;
 *  - the «last processed moment» is stored in the cache store (the `database` one in production, so it survives a restart) and the window is closed at the moment BEFORE the dispatch: a boundary is never
 *    missed and never processed twice. If it is missing, or older than a day (the scheduler was down; a cache clear), the command runs the full pass instead (every product, in queued chunks).
 *
 * The price lists table is small (tens of rows), so the one query is a scan of a few rows; no index on its dates was added (the task allowed one migration, for the column).
 */
class RefreshPriceBoundaries extends Command
{
    public const LAST_KEY = 'prices.boundaries.last_processed';

    protected $signature = 'prices:refresh-boundaries';

    protected $description = 'Refresh the stored sort prices of the products whose price list starts or ends since the last run (every minute).';

    public function handle(SortPriceRefreshDispatcher $dispatcher): int
    {
        $now = CarbonImmutable::now();
        $stored = Cache::get(self::LAST_KEY);
        $last = is_string($stored) ? CarbonImmutable::parse($stored) : null;

        if ($last === null || $last->lessThan($now->subDay())) {
            Cache::forever(self::LAST_KEY, $now->toIso8601String());
            $dispatcher->all();
            $this->info('The last processed moment is missing or older than a day: the full pass was queued.');

            return self::SUCCESS;
        }

        $lists = PriceListModel::query()
            ->where('status', 'active')
            ->where(function ($query) use ($last, $now): void {
                $query->where(fn ($from) => $from->where('valid_from', '>', $last)->where('valid_from', '<=', $now))
                    ->orWhere(fn ($until) => $until->where('valid_until', '>', $last)->where('valid_until', '<=', $now));
            })
            ->get(['id']);

        // the window is closed BEFORE anything is dispatched
        Cache::forever(self::LAST_KEY, $now->toIso8601String());

        foreach ($lists as $list) {
            $dispatcher->priceList((string) $list->id);
        }

        $lists->isEmpty() || $this->info("{$lists->count()} price list(s) crossed a boundary: their products were queued.");

        return self::SUCCESS;
    }
}
