<?php

namespace App\Console\Commands;

use App\Privacy\OpenMoneyOrders;
use App\Privacy\OrderAnonymiser;
use App\Privacy\OrderRetention;
use App\Privacy\PrivacySettings;
use App\Settings\StoreTimezone;
use Carbon\CarbonImmutable;
use EasyCo\Order\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `php artisan privacy:anonymise-orders [--dry-run] [--limit=500]` (ACC-4b, acc-4-gdpr-design.md D1, D8): anonymises the personal data of the orders that are
 * past the merchant's retention period (`privacy.order_retention_years`, default 10: placed before 1 January of the placement year + 1 + N, store time).
 * The shape of AnonymiseOrderIps: a plain Command, one settings read, one selection, an `->info()` summary line, SUCCESS. Scheduled DAILY at 03:10
 * (routes/console.php), `withoutOverlapping`.
 *
 * WHICH ORDERS: past the cutoff, not anonymised yet, FINISHED (delivered, cancelled, refunded), and without an open money matter (an owed refund, an
 * unreconciled bank transfer, an unfinished payment step: the admin's «needs attention» list). The others are SKIPPED and COUNTED, never touched: they come
 * back on the next run once they are finished and settled.
 *
 * `--dry-run` prints the count and the cutoff and writes nothing. `--limit` (1..5000, default 500) caps one run; the rest wait for the next. IDEMPOTENT
 * (`anonymised_at IS NULL` is part of the selection). CHUNKED: ONE TRANSACTION PER ORDER (OrderAnonymiser): an order that fails is logged and the others
 * stand. Only COUNTS are logged, and the number of an order that failed, never a value.
 */
class AnonymiseOrders extends Command
{
    public const DEFAULT_LIMIT = 500;

    public const MAX_LIMIT = 5000;

    protected $signature = 'privacy:anonymise-orders {--dry-run : Print the count and the cutoff, write nothing} {--limit= : At most this many orders in one run (1..5000, default 500)}';

    protected $description = 'Anonymise the personal data of finished orders past the retention period';

    public function handle(OrderRetention $retention, OrderAnonymiser $anonymiser, OpenMoneyOrders $openMoney, PrivacySettings $settings, StoreTimezone $timezone): int
    {
        $limit = $this->limit();

        if ($limit === null) {
            $this->error('--limit must be a whole number from 1 to '.self::MAX_LIMIT.'.');

            return self::INVALID;
        }

        $now = Date::now();
        $cutoff = $retention->cutoff($now->toDateTimeImmutable());
        $years = $settings->orderRetentionYears();
        $bound = CarbonImmutable::instance($cutoff)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
        $cutoffText = $cutoff->setTimezone($timezone->zone())->format('Y-m-d H:i T');

        $finished = array_map(static fn ($status): string => $status->value, Order::ANONYMISABLE_STATUSES);
        $open = $openMoney->ids();
        $past = static fn () => DB::table('orders')->where('placed_at', '<', $bound)->whereNull('anonymised_at');

        $skippedNotFinished = $past()->whereNotIn('status', $finished)->count();
        $skippedOpenMoney = $open === [] ? 0 : $past()->whereIn('status', $finished)->whereIn('id', $open)->count();
        $eligible = fn () => $past()->whereIn('status', $finished)->when($open !== [], fn ($query) => $query->whereNotIn('id', $open));
        $total = $eligible()->count();

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$total} order(s) placed before {$cutoffText} (retention {$years} year(s)) would be anonymised; skipped: {$skippedNotFinished} not finished, {$skippedOpenMoney} with an open money matter; nothing was changed.");

            return self::SUCCESS;
        }

        $anonymised = 0;
        $failed = 0;

        foreach ($eligible()->orderBy('id')->limit($limit)->pluck('id') as $id) {
            try {
                $anonymiser->anonymise((string) $id, $cutoff, $now->toDateTimeImmutable()) && $anonymised++;
            } catch (Throwable $e) {
                $failed++;
                Log::error('privacy.order_anonymise_failed', ['order_id' => (string) $id, 'exception' => $e::class]);
            }
        }

        Log::info('privacy.orders_anonymised', [
            'count' => $anonymised, 'failed' => $failed, 'skipped_not_finished' => $skippedNotFinished, 'skipped_open_money' => $skippedOpenMoney, 'retention_years' => $years,
        ]);

        $this->info("Anonymised {$anonymised} order(s) placed before {$cutoffText} (retention {$years} year(s)); failed: {$failed}; skipped: {$skippedNotFinished} not finished, {$skippedOpenMoney} with an open money matter; waiting for the next run: ".max(0, $total - $anonymised - $failed).'.');

        return self::SUCCESS;
    }

    private function limit(): ?int
    {
        $raw = $this->option('limit');

        if ($raw === null || $raw === '') {
            return self::DEFAULT_LIMIT;
        }

        if (! is_string($raw) || preg_match('/^[0-9]{1,5}$/D', $raw) !== 1 || (int) $raw < 1 || (int) $raw > self::MAX_LIMIT) {
            return null;
        }

        return (int) $raw;
    }
}
