<?php

namespace Tests\Feature\Account;

use App\Mail\SendAccountMailJob;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Extensibility\Hook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Account\Concerns\DrivesAccountApi;
use Tests\TestCase;

/** ACC-1 decisions 11 and the «no secrets in logs» rule: the whole account flow, with the logger and every hook spied on. */
class AccountNoSecretsInLogsTest extends TestCase
{
    use DrivesAccountApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driveAccountApi();
        config(['security.mode' => 'log', 'ratelimits.registration' => 100]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    /** @var list<string> every record handed to the logger (level, message, context as JSON) */
    private array $records = [];

    private function recordLogs(): void
    {
        $recorder = new class($this->records) extends \Psr\Log\AbstractLogger
        {
            public function __construct(private array &$records)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = $level.' '.$message.' '.json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        };

        app()->instance('log', $recorder);
        app()->instance(\Psr\Log\LoggerInterface::class, $recorder);
        \Illuminate\Support\Facades\Log::clearResolvedInstance('log');
    }

    public function test_no_log_line_and_no_hook_argument_ever_holds_a_password_a_token_or_a_signed_url(): void
    {
        $this->recordLogs();
        $fired = [];
        foreach (['account.registered', 'account.verified', 'account.password_changed', 'account.email_changed'] as $hook) {
            Hook::action($hook, function (...$args) use (&$fired, $hook): void {
                $fired[$hook][] = $args;
            });
        }

        $secrets = [$this->strong, 'a-brand-new-pass-77'];

        // register → verify (the link) → change password → forgot → reset → change email → confirm
        $this->postJson('/api/account/register', ['email' => 'flow@example.com', 'password' => $this->strong, 'password_confirmation' => $this->strong])->assertStatus(202);
        $verify = $this->linkParams($this->mails('account.verify', 'flow@example.com')[0]);
        $this->postJson('/api/account/verify', $verify)->assertStatus(200);
        $this->login('flow@example.com')->assertStatus(200);   // the verify link does not log in
        $this->putJson('/api/account/password', ['current_password' => $this->strong, 'password' => 'a-brand-new-pass-77', 'password_confirmation' => 'a-brand-new-pass-77'])->assertStatus(200);
        $this->postJson('/api/account/password/forgot', ['email' => 'flow@example.com'])->assertStatus(202);
        $reset = $this->linkParams($this->mails('account.password_reset', 'flow@example.com')[0]);
        $this->postJson('/api/account/password/reset', ['email' => 'flow@example.com', 'token' => $reset['token'], 'password' => $this->strong, 'password_confirmation' => $this->strong])->assertStatus(200);
        $this->login('flow@example.com')->assertStatus(200);
        $this->postJson('/api/account/email', ['current_password' => $this->strong, 'email' => 'moved@example.com'])->assertStatus(202);
        $confirm = $this->linkParams($this->mails('account.email_confirm', 'moved@example.com')[0]);
        $this->postJson('/api/account/email/confirm', $confirm)->assertStatus(200);
        $this->postJson('/api/account/login', ['email' => 'moved@example.com', 'password' => 'wrong-password-for-log-1'])->assertStatus(401);

        // the four hooks fired, each with the account id ONLY
        $id = (string) AccountModel::first()->getKey();
        $names = array_keys($fired);
        sort($names);
        $this->assertSame(['account.email_changed', 'account.password_changed', 'account.registered', 'account.verified'], $names);
        foreach ($fired as $hook => $calls) {
            foreach ($calls as $args) {
                $this->assertSame([$id], $args, "{$hook} carries the account id and nothing else");
            }
        }

        // the logger: nothing it received contains a password, a token, a signature or a link
        $forbidden = array_merge($secrets, ['wrong-password-for-log-1', $reset['token'], $confirm['token'], $verify['signature'], $reset['token'], $confirm['signature'], 'signature=', '/account/verify', '/account/reset', '/account/email/confirm', 'flow@example.com', 'moved@example.com']);
        // the recorder really sees the logger: a sentinel written the way the app writes
        \Illuminate\Support\Facades\Log::warning('sentinel line');
        $this->assertStringContainsString('sentinel line', implode("
", $this->records));
        foreach ($this->records as $line) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $line, 'a log line holds a secret or an address');
            }
        }
    }

    /** the queue payload of a mail job is encrypted (the link and the address are not readable in the queue table) */
    public function test_the_mail_job_is_queued_encrypted(): void
    {
        $job = new SendAccountMailJob('account.verify', 'a@example.com', 'en', ['action_link' => ['url' => 'https://shop.test/account/verify?signature=zzz', 'label' => 'verify']]);
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeEncrypted::class, $job);

        // the payload the queue would store: Illuminate\Queue\Queue::createObjectPayload encrypts the command of a ShouldBeEncrypted job
        $queue = new \Illuminate\Queue\SyncQueue();
        $queue->setContainer(app());
        $payload = (new \ReflectionMethod($queue, 'createPayload'))->invoke($queue, $job, 'mail-transactional');

        $this->assertStringNotContainsString('signature=zzz', $payload);
        $this->assertStringNotContainsString('a@example.com', $payload);
    }
}
