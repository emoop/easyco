<?php

namespace Tests\Feature\Privacy;

use App\Mail\AccountMails;
use App\Mail\SendAccountMailJob;
use App\Privacy\Persistence\PrivacyRequestModel;
use App\Privacy\PrivacyConfirmationRequester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ACC-4e-1: `privacy:ask-confirmation` with a request that is already open (PrivacyRequestAlreadyOpen), `--resend`, and a mail that cannot be queued (PrivacyMailNotQueued): a clear line and an exit code, never a stack trace.
 */
class AskPrivacyConfirmationCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://shop.example']);
    }

    /** ★ asking twice for the same email and kind: one clear line, FAILURE, still one row, still one mail */
    public function test_a_second_run_without_the_option_says_it_is_already_waiting_and_makes_nothing(): void
    {
        Queue::fake();
        $this->artisan('privacy:ask-confirmation', ['email' => 'me@example.com', 'kind' => 'export'])->assertSuccessful();
        $row = PrivacyRequestModel::query()->sole();

        $exit = \Illuminate\Support\Facades\Artisan::call('privacy:ask-confirmation', ['email' => 'Me@Example.com', 'kind' => 'export']);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(1, $exit, 'FAILURE');
        $this->assertStringContainsString("A request {$row->getKey()} is already waiting for confirmation; its link is valid until", $output);
        $this->assertStringContainsString('Run again with --resend', $output);
        $this->assertSame(1, substr_count(trim($output), "
") + 1, 'one line');
        $this->assertStringNotContainsString('Stack trace', $output);

        $this->assertSame(1, PrivacyRequestModel::query()->count());
        Queue::assertPushed(SendAccountMailJob::class, 1);
    }

    public function test_with_resend_the_same_row_gets_a_new_link_that_ends_where_the_row_ends(): void
    {
        Queue::fake();
        $this->artisan('privacy:ask-confirmation', ['email' => 'me@example.com', 'kind' => 'erase'])->assertSuccessful();
        $row = PrivacyRequestModel::query()->sole();
        $this->travel(3)->days();

        $this->artisan('privacy:ask-confirmation', ['email' => 'me@example.com', 'kind' => 'erase', '--resend' => true])
            ->expectsOutputToContain('Request id: '.$row->getKey())
            ->expectsOutputToContain('Status: awaiting_confirmation')
            ->doesntExpectOutputToContain('signature')
            ->assertSuccessful();

        $this->assertSame(1, PrivacyRequestModel::query()->count(), 'the same row');
        $jobs = Queue::pushed(SendAccountMailJob::class);
        $this->assertCount(2, $jobs);

        $end = $row->created_at->getTimestamp() + PrivacyConfirmationRequester::VALID_DAYS * 86400;

        foreach ($jobs as $job) {
            parse_str((string) parse_url($job->links['action_link']['url'], PHP_URL_QUERY), $q);
            $this->assertSame($end, (int) $q['expires']);
            $this->assertSame((string) $row->getKey(), $q['id']);
        }

    }

    public function test_with_resend_and_nothing_open_it_just_makes_the_request(): void
    {
        Queue::fake();

        $this->artisan('privacy:ask-confirmation', ['email' => 'me@example.com', 'kind' => 'export', '--resend' => true])->assertSuccessful();

        $this->assertSame(1, PrivacyRequestModel::query()->count());
        Queue::assertPushed(SendAccountMailJob::class, 1);
    }

    public function test_a_mail_that_cannot_be_queued_names_the_row_and_fails(): void
    {
        $this->mock(AccountMails::class, function ($mock): void {
            $mock->shouldReceive('privacyConfirmUrl')->andReturn('https://shop.example/x');
            $mock->shouldReceive('privacyConfirmation')->andReturn(false);
        });

        $this->artisan('privacy:ask-confirmation', ['email' => 'me@example.com', 'kind' => 'export'])
            ->expectsOutputToContain('was written, but the mail could not be queued')
            ->assertFailed();

        $row = PrivacyRequestModel::query()->sole();
        $this->assertSame('awaiting_confirmation', $row->status, 'the row is kept for a resend');
    }
}
