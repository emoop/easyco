<?php

namespace App\Console\Commands;

use App\Privacy\PrivacySettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan mail:prune-log` (ACC-4a, acc-4-gdpr-design.md section 5): `mail_log.to_email` is a clear address, and the table had no end of life. Deletes
 * the rows queued more than `privacy.mail_log_retention_days` ago (30..3650, default 365). IDEMPOTENT; a cheap no-op when nothing has aged out; only the
 * COUNT is logged. Deleted in chunks, so a big backlog never holds one long lock.
 *
 * NO DUPLICATE CONFIRMATIONS: a deleted `order.confirmation:{id}` row is the very key that stops a second confirmation. It is safe to delete because the ONLY
 * other sender of that mail, mail:reconcile-order-confirmations, looks at orders placed within the last WINDOW_HOURS (24) and the hook sends once, at placement:
 * a row older than the shortest retention (30 days) belongs to an order that is no longer a candidate. The pair is pinned by a test
 * (MailLogPruneSafetyTest): the window must stay shorter than PrivacySettings::MIN_MAIL_LOG_RETENTION_DAYS.
 */
class PruneMailLog extends Command
{
    private const CHUNK = 1000;

    protected $signature = 'mail:prune-log';

    protected $description = 'Delete mail log rows older than privacy.mail_log_retention_days';

    public function handle(PrivacySettings $settings): int
    {
        $days = $settings->mailLogRetentionDays();
        $cutoff = now()->subDays($days);
        $deleted = 0;

        do {
            $ids = DB::table('mail_log')->where('queued_at', '<', $cutoff)->orderBy('id')->limit(self::CHUNK)->pluck('id');
            $count = $ids->isEmpty() ? 0 : DB::table('mail_log')->whereIn('id', $ids)->delete();
            $deleted += $count;
        } while ($count === self::CHUNK);

        if ($deleted > 0) {
            Log::info('privacy.mail_log_pruned', ['count' => $deleted, 'retention_days' => $days]);
        }

        $this->info("Deleted {$deleted} mail log row(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
