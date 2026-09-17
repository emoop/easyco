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
