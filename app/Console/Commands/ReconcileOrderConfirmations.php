<?php

namespace App\Console\Commands;

use App\Mail\MailDispatcher;
use App\Mail\MailTemplates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan mail:reconcile-order-confirmations` — closes the gap between "the order committed" and "order.placed
 * fired" (mail-design.md §6.1): the process can die in between, and then the hook never fires.
 *
 * It queues the confirmation for orders placed within the last 24 hours, at least 5 minutes ago (a request still in
 * flight is not a gap), that have NO `mail_log` row for `order.confirmation:{id}`. An order that has a row — in
 * any status — is left alone; so are cancelled and refunded orders. Scheduled every 10 minutes (routes/console.php).
 *
 * Two reads per run (candidates, then the existing keys), at most 200 orders; the rest wait for the next run.
 */
class ReconcileOrderConfirmations extends Command
{
    protected $signature = 'mail:reconcile-order-confirmations';

    protected $description = 'Queue the confirmation mail for recent orders whose order.placed hook never produced one';

    public const BATCH = 200;

    public const MIN_AGE_MINUTES = 5;

    public const WINDOW_HOURS = 24;

    public function handle(MailDispatcher $dispatcher): int
    {
        $candidates = DB::table('orders')
            ->where('placed_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->where('placed_at', '<=', now()->subMinutes(self::MIN_AGE_MINUTES))
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get(['id', 'email']);

        if ($candidates->isEmpty()) {
            $this->info('Queued 0 order confirmation(s).');

            return self::SUCCESS;
        }

        $prefix = MailTemplates::ORDER_CONFIRMATION.':';
        $existing = DB::table('mail_log')
            ->whereIn('idempotency_key', $candidates->map(fn (object $order): string => $prefix.$order->id)->all())
            ->pluck('idempotency_key')
            ->flip();

        $queued = 0;

        foreach ($candidates as $order) {
            if ($existing->has($prefix.$order->id)) {
                continue;
            }

            if ($dispatcher->queueOrderConfirmation((string) $order->id, (string) $order->email)) {
                $queued++;
            }
        }

        $this->info("Queued {$queued} order confirmation(s).");

        return self::SUCCESS;
    }
}
