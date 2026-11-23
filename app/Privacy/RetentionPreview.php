<?php

namespace App\Privacy;

use Carbon\CarbonImmutable;
use EasyCo\Order\Order;
use Illuminate\Support\Facades\DB;

/**
 * ACC-4e-2 (acc-4-gdpr-design.md section 7): how many orders the NEXT `privacy:anonymise-orders` run would anonymise for a given retention. The settings page asks it before a LOWER retention is saved
 * (anonymising is for good). The cutoff comes from OrderRetention::cutoffForYears (the one place the date rule lives); the eligibility is the command's own: not anonymised yet, a FINISHED status
 * (Order::ANONYMISABLE_STATUSES) and no open money matter (OpenMoneyOrders). Read only.
 */
final class RetentionPreview
{
    public function __construct(
        private readonly OrderRetention $retention,
        private readonly OpenMoneyOrders $openMoney,
    ) {
    }

    public function count(int $years): int
    {
        $cutoff = $this->retention->cutoffForYears($years);
        $bound = CarbonImmutable::instance($cutoff)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
        $finished = array_map(static fn ($status): string => $status->value, Order::ANONYMISABLE_STATUSES);
        $open = $this->openMoney->ids();

        return DB::table('orders')
            ->where('placed_at', '<', $bound)
            ->whereNull('anonymised_at')
            ->whereIn('status', $finished)
            ->when($open !== [], fn ($query) => $query->whereNotIn('id', $open))
            ->count();
    }
}
