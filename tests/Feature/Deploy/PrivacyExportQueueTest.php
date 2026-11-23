<?php

namespace Tests\Feature\Deploy;

use App\Privacy\BuildPrivacyExportJob;
use Tests\TestCase;

/**
 * Incident DEP-3 (2026-11-22): a job on a queue the compose `queue` worker does not serve never runs and never fails. The privacy export's job (ACC-4c) must be on a queue the worker serves, and
 * its timeout must stay below the worker's `--timeout`.
 */
class PrivacyExportQueueTest extends TestCase
{
    public function test_the_export_job_queue_is_served_by_the_compose_worker(): void
    {
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));

        $this->assertSame(1, preg_match('/"queue:work",\s*"--queue=([a-z,\-]+)"/', $compose, $m), 'the queue service starts `queue:work --queue=...`');
        $this->assertContains((new BuildPrivacyExportJob(1))->queue, explode(',', $m[1]), 'the export job must be on a queue the worker serves');
        $this->assertSame('default', BuildPrivacyExportJob::QUEUE);
    }

    public function test_the_job_timeout_is_below_the_worker_timeout_of_the_compose_file(): void
    {
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));

        $this->assertSame(1, preg_match('/--timeout=\$\{QUEUE_WORKER_TIMEOUT:-(\d+)\}/', $compose, $m));
        $this->assertLessThan((int) $m[1], (new BuildPrivacyExportJob(1))->timeout);
    }
}
