<?php

namespace App\Console\Commands;

use App\Models\ActivityLogModel;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * `php artisan activity-log:prune` — deletes activity_log rows older
 * than the configured admin.activity_log_retention_months (the same
 * SiteSettings key LocaleSettings' Activity Log tab writes) — mirrors
 * PruneExpiredCarts' own shape (a plain Command, a repository/model
 * read, an ->info() summary line, SUCCESS).
 *
 * SKIPS ENTIRELY WHEN THE LOG ITSELF IS DISABLED (admin.
 * activity_log_enabled !== '1'), rather than running a delete query
 * that (by construction) finds nothing meaningful to remove: if
 * logging is off, whatever rows already exist are the merchant's own
 * historical record from when it WAS on, and this command has no
 * independent signal for "the merchant wants old data gone right
 * now" — that is what running the command manually, or re-enabling
 * the setting, is for. Automatically running a delete query against
 * a table nobody is currently using it for is waste, not safety.
 *
 * UNLIKE cart:prune, THIS COMMAND IS ACTUALLY SCHEDULED — a real,
 * confirmed gap found while reading cart:prune as this task's own
 * named precedent: cart:prune's own class docblock states plainly
 * that nothing schedules it yet ("this project has no scheduler wired
 * up at all"), so there was no real existing registration to mirror.
 * See routes/console.php for the actual Schedule::command() entry
 * this task adds — the real, current Laravel 13 mechanism (no
 * app/Console/Kernel.php in this project's skeleton), not a copy of a
 * cart:prune registration that doesn't exist.
 */
class PruneActivityLog extends Command
{
    protected $signature = 'activity-log:prune';

    protected $description = 'Delete activity log rows older than the configured retention period (skipped entirely while the log is disabled)';

    public function handle(SiteSettingsRepository $settings): int
    {
        if ($settings->get('admin.activity_log_enabled') !== '1') {
            $this->info('Activity log is disabled — skipping prune.');

            return self::SUCCESS;
        }

        $retentionMonths = (int) ($settings->get('admin.activity_log_retention_months') ?? 12);
        $cutoff = Date::now()->subMonths($retentionMonths);

        $deleted = ActivityLogModel::where('occurred_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} activity log row(s) older than {$retentionMonths} month(s).");

        return self::SUCCESS;
    }
}
