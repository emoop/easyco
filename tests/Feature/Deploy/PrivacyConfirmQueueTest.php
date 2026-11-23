<?php

namespace Tests\Feature\Deploy;

use App\Mail\AccountMails;
use App\Mail\MailTemplates;
use App\Mail\SendAccountMailJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Incident DEP-3 (2026-11-22): a job on a queue the compose `queue` worker does not serve never runs and never fails. The `privacy.confirm` mail (ACC-4d) must be on a queue the worker serves,
 * both in the template registry and in the job that is really dispatched.
 */
class PrivacyConfirmQueueTest extends TestCase
{
    /** @return list<string> */
    private function served(): array
    {
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));

        $this->assertSame(1, preg_match('/"queue:work",\s*"--queue=([a-z,\-]+)"/', $compose, $m), 'the queue service starts `queue:work --queue=...`');

        return explode(',', $m[1]);
    }

    public function test_the_privacy_confirm_mail_is_on_a_queue_the_compose_worker_serves(): void
    {
        $this->assertContains(app(MailTemplates::class)->get(MailTemplates::PRIVACY_CONFIRM)->queue, $this->served());

        Queue::fake();
        app(AccountMails::class)->privacyConfirmation('me@example.com', 'export', 'https://shop.example/account/privacy/confirm?id=1', 7);

        Queue::assertPushed(SendAccountMailJob::class, fn (SendAccountMailJob $job): bool => $job->templateKey === MailTemplates::PRIVACY_CONFIRM && in_array($job->queue, $this->served(), true));
    }
}
