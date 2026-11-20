<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// activity-log:prune's own class docblock: unlike cart:prune (whose
// own docblock states plainly that nothing schedules it — this
// project has no scheduler wired up at all, confirmed by this file
// having been empty of any Schedule:: entry until now), this command
// IS actually scheduled, using Laravel 13's real, current mechanism
// (routes/console.php + the Schedule facade — no app/Console/Kernel.php
// exists in this project's skeleton). Daily: the command itself is a
// cheap no-op whenever nothing has aged past the configured retention,
// so a tight cadence costs nothing and keeps the log from growing
// unbounded for longer than a day past the merchant's own setting.
Schedule::command('activity-log:prune')->daily();

// mail-design.md section 6.1: the process can die between an order's commit and the order.placed hook, and then no
// confirmation mail is ever queued. Every 10 minutes the orders of the last 24 hours (older than 5 minutes) that have
// no mail_log row are queued. withoutOverlapping: a slow run must not start a second one on top of it.
Schedule::command('mail:reconcile-order-confirmations')->everyTenMinutes()->withoutOverlapping();

// order-context-design.md 7.3: the full client IP of an order lives `privacy.ip_full_retention_days` (default 30) and is then replaced by its
// anonymised form. Daily, like activity-log:prune: the command is a cheap no-op when nothing has aged out, and counts (never values) are logged.
Schedule::command('privacy:anonymise-order-ips')->daily();

// cod-protection-design.md 3.1: a cash-on-delivery block is personal data with its own clock; the ones that ended more than 30 days ago are deleted.
// Daily, like the other prune commands: a cheap no-op when nothing has aged out; only counts are logged.
Schedule::command('order-blocks:prune')->daily();

// ACC-1: an account that never verified its email within 7 days (and has no order) is deleted; a cheap no-op when nothing has aged out, only the count is logged.
Schedule::command('accounts:prune-unverified')->daily();

// P6a: the stored sort price of the products (`catalog_products.price_from_minor`). EVERY MINUTE a cheap check (ONE query: does an active price list start or end in the window since the last run?) refreshes only the
// products such a list can touch; once a night, at 03:30 SHOP time, the whole catalog is recomputed in chunks (the safety net, writes only what changed). The shop's time is read when the schedule RUNS (the `when()`
// closure), never when this file loads, so no artisan command pays a settings query at boot.
Schedule::command('prices:refresh-boundaries')->everyMinute()->withoutOverlapping();
Schedule::command('prices:refresh-from')->everyMinute()->withoutOverlapping()
    ->when(static fn (): bool => (new DateTimeImmutable('now', app(App\Settings\StoreTimezone::class)->zone()))->format('H:i') === '03:30');

// P8d: the bot-challenge mode is pushed to the cache layer every minute (Varnish forgets it on a restart). The null switch does nothing until P8c.
Schedule::command('security:push-bot-challenge')->everyMinute()->withoutOverlapping();

// ACC-4a (acc-4-gdpr-design.md section 5): the privacy housekeeping, daily and staggered, each a cheap no-op when nothing has aged out (only counts are logged).
// - the clear email/phone of a forget log row live 30 days (D6), then only the hashes stay;
// - a request that waited more than 24 h for its confirmation expires;
// - mail_log.to_email is a clear address: rows older than `privacy.mail_log_retention_days` (default 365) are deleted;
// - cart:prune (it was safe to schedule since shipping stage 4h, it only never was): unclaimed carts past their expiry, a claimed cart is never deleted;
// - an unused password reset token (a hash + an email, no foreign key) is deleted after its own 60 minutes;
// - failed jobs keep their payload (an email, a link): 720 hours, then gone.
// Session GC needs nothing here: SESSION_DRIVER=database with config/session.php `lottery` [2, 100] collects garbage on the requests that start a session.
Schedule::command('privacy:expire-clear-view')->dailyAt('02:10');
Schedule::command('privacy:expire-requests')->dailyAt('02:20');
Schedule::command('mail:prune-log')->dailyAt('02:30');
Schedule::command('cart:prune')->dailyAt('02:40');
Schedule::command('accounts:prune-reset-tokens')->dailyAt('02:50');
Schedule::command('queue:prune-failed --hours=720')->dailyAt('03:00');
