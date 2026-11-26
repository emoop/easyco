<?php

namespace App\Couriers;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The points sync of ONE courier, queued (courier-integration-design.md §3.4). THE PAYLOAD IS THE CARRIER CODE AND WHO ASKED,
 * nothing else: the credentials are read from the vault inside the run (§10.1). A persistent queue worker keeps the booted
 * code (CLAUDE.md): after changing this class run `php artisan queue:restart`.
 *
 * It does nothing for a courier that is no longer enabled when the job runs (switched off while it waited).
 */
final class SyncCourierPointsJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $carrierCode, public readonly string $trigger = 'schedule', public readonly bool $withSettlements = false) {}

    public function handle(PickupPointSyncer $syncer): void
    {
        $syncer->run($this->carrierCode, $this->trigger, $this->withSettlements);
    }
}
