<?php

namespace Tests\Feature\Deploy;

use App\Jobs\QueueHeartbeat;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Tests\TestCase;

/**
 * DEP-2: the files the Docker healthchecks of `scheduler` and `queue` read (docker-compose.yml). Docker itself is not tested here: only that the
 * scheduler has the two heartbeat entries with the cadence the healthcheck windows assume, and that the job touches its file.
 */
class HeartbeatTest extends TestCase
{
    private function event(string $name): Event
    {
        foreach (app(Schedule::class)->events() as $event) {
            if ($event->description === $name) {
                return $event;
            }
        }

        $this->fail("the schedule has no entry named {$name}");
    }

    public function test_the_scheduler_heartbeat_runs_every_minute_and_touches_its_file(): void
    {
        $event = $this->event('scheduler-heartbeat');
        $file = storage_path('framework/scheduler-heartbeat');
        @unlink($file);

        $this->assertSame('* * * * *', $event->expression, 'every minute: the healthcheck allows 3 minutes');
        $event->run($this->app);
        $this->assertFileExists($file);
        $this->assertLessThan(5, time() - filemtime($file));
        $this->assertFalse($event->withoutOverlapping, 'not needed');
    }

    public function test_the_queue_heartbeat_is_dispatched_every_two_minutes(): void
    {
        $this->assertSame('*/2 * * * *', $this->event('queue-heartbeat')->expression, 'the healthcheck allows 6 minutes');
    }

    public function test_the_queue_heartbeat_job_touches_its_file_and_is_unique(): void
    {
        $file = QueueHeartbeat::path();
        @unlink($file);

        (new QueueHeartbeat)->handle();

        $this->assertFileExists($file);
        $this->assertLessThan(5, time() - filemtime($file));
        $this->assertSame(storage_path('framework/queue-heartbeat'), $file, 'the path docker-compose.yml checks');
        $this->assertInstanceOf(ShouldBeUnique::class, new QueueHeartbeat, 'a stopped queue must not pile heartbeats up');
    }

    public function test_the_compose_healthchecks_read_the_files_the_heartbeats_write(): void
    {
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));

        $this->assertStringContainsString('/var/www/html/storage/framework/scheduler-heartbeat -mmin -3', $compose);
        $this->assertStringContainsString('/var/www/html/storage/framework/queue-heartbeat -mmin -6', $compose);
    }
}
