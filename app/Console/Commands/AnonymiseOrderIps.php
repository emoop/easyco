<?php

namespace App\Console\Commands;

use App\Models\OrderAttributionModel;
use App\Settings\OrderContextSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * `php artisan privacy:anonymise-order-ips` — replaces the full client address of every order older than `privacy.ip_full_retention_days`
 * (default 30) by its anonymised form, IN PLACE, and stamps `ip_anonymised_at` (order-context-design.md 7.2, 7.3). IPv4 keeps its first three
 * octets, IPv6 its routing prefix: Symfony's own IpUtils::anonymize(), nothing hand-rolled.
 *
 * THE SHAPE OF PruneActivityLog: a plain Command, one settings read, one `where`, an `->info()` summary line, SUCCESS. Scheduled DAILY in
 * routes/console.php: a cheap no-op when nothing has aged out (the `attr_captured_at_index` pays for the age test).
 *
 * IDEMPOTENT: a row that already holds `ip_anonymised_at` is never selected, so a second run changes and logs nothing.
 * `--dry-run` writes NOTHING and only says how many rows it would anonymise.
 *
 * IT LOGS COUNTS, NEVER VALUES: no log line, output line or exception message carries an address, full or anonymised (section 7.2). A value
 * that is not an IP address at all (it cannot be anonymised) is cleared rather than kept: the column never holds a full address past its
 * window, whatever it was.
 */
class AnonymiseOrderIps extends Command
{
    protected $signature = 'privacy:anonymise-order-ips {--dry-run : Count the rows that would be anonymised and change nothing}';

    protected $description = 'Replace the full client IP of orders older than the configured retention window by its anonymised form (counts only are logged)';

    public function handle(OrderContextSettings $settings): int
    {
        $days = $settings->ipFullRetentionDays();
        $cutoff = Date::now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $query = OrderAttributionModel::query()
            ->whereNotNull('ip')
            ->whereNull('ip_anonymised_at')
            ->where('captured_at', '<', $cutoff);

        if ($dryRun) {
            $this->info('Dry run: '.$query->count()." order address(es) older than {$days} day(s) would be anonymised; nothing was changed.");

            return self::SUCCESS;
        }

        $anonymised = 0;
        $now = Date::now();

        // Chunked by id: each row is rewritten as it is read, and the next chunk starts after the last id, so a row is never read twice.
        $query->chunkById(500, function ($rows) use (&$anonymised, $now): void {
            foreach ($rows as $row) {
                $ip = (string) $row->ip;

                $row->ip = filter_var($ip, FILTER_VALIDATE_IP) === false ? null : IpUtils::anonymize($ip);
                $row->ip_anonymised_at = $now;
                $row->save();
                $anonymised++;
            }
        });

        if ($anonymised > 0) {
            Log::info('privacy.order_ips_anonymised', ['count' => $anonymised, 'retention_days' => $days]);
        }

        $this->info("Anonymised {$anonymised} order address(es) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
