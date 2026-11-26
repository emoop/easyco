<?php

namespace App\Console\Commands;

use App\Couriers\CourierDirectory;
use App\Couriers\SyncCourierPointsJob;
use Illuminate\Console\Command;

/**
 * `php artisan couriers:sync {carrier?} {--now}` (courier-integration-design.md §3.4): refreshes the local list of courier
 * points. Scheduled nightly at 03:40 WITHOUT an argument, which queues one job per ENABLED courier: a disabled courier is not
 * queued and not called. With a carrier code it syncs that courier only, and REFUSES (exit 1) when it is not enabled.
 * `--now` runs the sync in this process instead of queueing it (for a first check on a dev machine). Prints counts, never a response.
 */
class SyncCouriers extends Command
{
    protected $signature = 'couriers:sync {carrier? : a courier code, e.g. boxnow} {--now : run here instead of queueing} {--settlements : rebuild the courier settlements first}';

    protected $description = 'Refresh the local list of courier pickup points (enabled couriers only)';

    public function handle(CourierDirectory $couriers): int
    {
        $code = $this->argument('carrier');

        if ($code !== null) {
            if (! $couriers->isEnabled((string) $code)) {
                $this->error("The courier \"{$code}\" is not installed or not enabled.");

                return self::FAILURE;
            }

            $codes = [(string) $code];
        } else {
            $codes = array_map(static fn ($descriptor): string => $descriptor->code(), $couriers->enabled());
        }

        foreach ($codes as $carrier) {
            $job = new SyncCourierPointsJob($carrier, $code === null ? 'schedule' : 'cli', (bool) $this->option('settlements'));

            if ($this->option('now')) {
                SyncCourierPointsJob::dispatchSync($carrier, $job->trigger, $job->withSettlements);
            } else {
                dispatch($job);
            }

            $this->info($this->option('now') ? "Synced {$carrier}." : "Queued {$carrier}.");
        }

        if ($codes === []) {
            $this->line('No courier is enabled.');
        }

        return self::SUCCESS;
    }
}
