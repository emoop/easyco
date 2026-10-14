<?php

namespace App\Console\Commands;

use App\Services\OrderBlockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan order-blocks:prune` — deletes the cash-on-delivery blocks that ENDED more than 30 days ago (revoked, or expired), so staff can still
 * see "this was blocked until last week" but a personal-data row does not outlive its purpose (cod-protection-design.md section 3.1). A PERMANENT
 * block stays until it is revoked; an active or recently ended one is never touched. Scheduled DAILY in routes/console.php (a cheap no-op when
 * nothing has aged out). It reports and logs COUNTS only: never an identifier.
 */
class PruneOrderBlocks extends Command
{
    protected $signature = 'order-blocks:prune';

    protected $description = 'Delete cash-on-delivery blocks that ended more than 30 days ago (counts only are logged)';

    public function handle(OrderBlockService $blocks): int
    {
        $deleted = $blocks->prune();

        if ($deleted > 0) {
            Log::info('order_blocks.pruned', ['count' => $deleted]);
        }

        $this->info('Pruned '.$deleted.' order block(s) that ended more than '.OrderBlockService::PRUNE_AFTER_DAYS.' days ago.');

        return self::SUCCESS;
    }
}
