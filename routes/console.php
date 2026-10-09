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
