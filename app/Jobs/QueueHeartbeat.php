<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * DEP-2: the heartbeat of the `queue` container. The scheduler dispatches it every two minutes (routes/console.php); the worker that runs it touches
 * `storage/framework/queue-heartbeat`, and the container's healthcheck (docker-compose.yml) passes while that file is younger than six minutes. So «healthy»
 * means a job was dispatched, stored, picked up and run end to end, not only that a process exists. It does nothing else and logs nothing.
 *
 * ShouldBeUnique: while the queue is down the scheduler keeps ticking; without the lock a backlog of heartbeats would pile up and run in a burst.
 */
final class QueueHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 600;

    public static function path(): string
    {
        return storage_path('framework/queue-heartbeat');
    }

    public function handle(): void
    {
        touch(self::path());
    }
}
