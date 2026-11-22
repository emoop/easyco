<?php

namespace Tests\Feature\Deploy;

use App\Mail\QueueHealth;
use Tests\TestCase;

/**
 * MAIL-DIAG: the Docker `queue` service must serve every queue the app dispatches mail on. Without `--queue` a worker serves only `default`, and customer-account and order mails
 * (queue `mail-transactional`) wait unsent forever: nothing fails, nothing is logged, Mailpit stays empty.
 */
class QueueWorkerQueuesTest extends TestCase
{
    public function test_the_compose_queue_worker_serves_the_mail_queues_and_default(): void
    {
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));

        $this->assertSame(1, preg_match('/"queue:work",\s*"--queue=([a-z,\-]+)"/', $compose, $m), 'the queue service starts `queue:work --queue=...`');

        $served = explode(',', $m[1]);

        foreach ([...QueueHealth::QUEUES, 'default'] as $queue) {
            $this->assertContains($queue, $served, "the worker must serve the `{$queue}` queue");
        }

        $this->assertSame('mail-transactional', $served[0], 'order matters: transactional mail first, a campaign never delays it');
    }
}
