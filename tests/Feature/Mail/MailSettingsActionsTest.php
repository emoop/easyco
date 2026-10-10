<?php

namespace Tests\Feature\Mail;

use App\Filament\Pages\Settings\MailSettings;
use App\Mail\Dns\DnsLookup;
use App\Mail\RenderedMailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

class MailSettingsActionsTest extends TestCase
{
    use ActsAsMailStaff;
    use RefreshDatabase;

    private function fakeLookup(array $records = [], ?\Closure $each = null): DnsLookup
    {
        $lookup = new class($records, $each) implements DnsLookup {
            /** @var list<string> */
            public array $hosts = [];

            public function __construct(private readonly array $records, private readonly ?\Closure $each)
            {
            }

            public function lookup(string $host, bool $includeCname = false): array
            {
                $this->hosts[] = $host;

                if ($this->each !== null) {
                    return ($this->each)($host, $includeCname);
                }

                return $this->records[$host] ?? [];
            }
        };
        $this->app->instance(DnsLookup::class, $lookup);

        return $lookup;
    }

    // ---- Send test email ----------------------------------------------------------------------------------

    public function test_the_test_email_defaults_to_the_staff_members_own_address_and_uses_the_configured_transport(): void
    {
        $this->actAsPanelAdministrator('owner@shop.example');
        Mail::fake();

        $component = Livewire::test(MailSettings::class);
        $this->assertSame('owner@shop.example', $component->get('data.test_recipient'));

        $component->call('sendTestEmail');

        Mail::assertSent(RenderedMailable::class, fn ($mail) => $mail->hasTo('owner@shop.example'));
        $this->assertTrue($component->get('testOk'));
    }

    public function test_a_test_send_writes_no_mail_log_rows_and_queues_nothing(): void
    {
        $this->actAsPanelAdministrator();
        config(['mail.default' => 'array']);

        Livewire::test(MailSettings::class)->call('sendTestEmail');

        $this->assertSame(0, DB::table('mail_log')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_another_address_is_limited_to_one_per_minute_per_user(): void
    {
        $this->actAsPanelAdministrator('owner@shop.example');
        Mail::fake();
        RateLimiter::clear('mail-test:1');

        $component = Livewire::test(MailSettings::class)->fillForm(['test_recipient' => 'someone@else.example']);
        $component->call('sendTestEmail');
        $this->assertTrue($component->get('testOk'));

        $component->call('sendTestEmail');
        $this->assertFalse($component->get('testOk'));
        $this->assertStringContainsString('Please wait', (string) $component->get('testResult'));
        Mail::assertSentCount(1);

        // Your own address is not limited.
        $component->fillForm(['test_recipient' => 'owner@shop.example'])->call('sendTestEmail');
        Mail::assertSentCount(2);
    }

    public function test_the_limit_is_per_user_not_global(): void
    {
        Mail::fake();

        $this->actAsPanelAdministrator('first@shop.example');
        Livewire::test(MailSettings::class)->fillForm(['test_recipient' => 'a@else.example'])->call('sendTestEmail');

        $second = $this->staffWithRole('Administrator', 'second@shop.example');
        $this->actingAs($second, 'staff');
        $component = Livewire::test(MailSettings::class)->fillForm(['test_recipient' => 'b@else.example'])->call('sendTestEmail');

        $this->assertTrue($component->get('testOk'), 'a second staff member has their own allowance');
        Mail::assertSentCount(2);
    }

    /** @return array<string, array{0: string}> */
    public static function badRecipients(): array
    {
        return [
            'not an address' => ['nope'],
            'two recipients' => ['a@b.co,c@d.fr'],
            'crlf bcc' => ["a@b.co\r\nBcc: x@y.zz"],
            'lf' => ["a@b.co\nBcc: x@y.zz"],
            'display name' => ['Me <a@b.co>'],
            'empty' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badRecipients')]
    public function test_invalid_recipients_are_refused_and_nothing_is_sent_or_counted(string $bad): void
    {
        $this->actAsPanelAdministrator();
        Mail::fake();

        $component = Livewire::test(MailSettings::class)->fillForm(['test_recipient' => $bad])->call('sendTestEmail');

        $this->assertFalse($component->get('testOk'));
        $this->assertSame(__('mail.test.invalid_address'), $component->get('testResult'));
        Mail::assertNothingSent();
        $this->assertFalse(RateLimiter::tooManyAttempts('mail-test:1', 1), 'a refused address does not use the allowance');
    }

    public function test_the_transports_real_error_is_shown_without_the_password_the_username_or_urls(): void
    {
        $this->actAsPanelAdministrator();
        $this->storeSetting('mail.transport', 'smtp');
        $this->storeSetting('mail.smtp.host', 'smtp.provider.example');
        $this->storeSetting('mail.smtp.username', 'bobthebuilder');
        $this->storeSetting('mail.smtp.password', Crypt::encryptString('hunter2pass'));

        Mail::extend('smtp', fn () => new class extends AbstractTransport {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Authentication failed for bobthebuilder with hunter2pass at smtp://bobthebuilder:hunter2pass@smtp.provider.example/ -- 535 5.7.8 Username and Password not accepted');
            }

            public function __toString(): string
            {
                return 'fake://';
            }
        });

        $component = Livewire::test(MailSettings::class)->call('sendTestEmail');
        $text = (string) $component->get('testResult');

        $this->assertFalse($component->get('testOk'));
        $this->assertStringContainsString('Authentication failed', $text, 'the real reason is kept');
        $this->assertStringContainsString('Username and Password not accepted', $text);
        $this->assertStringNotContainsString('hunter2pass', $text);
        $this->assertStringNotContainsString('bobthebuilder', $text);
        $this->assertStringNotContainsString('smtp://', $text);
        $this->assertStringNotContainsString('hunter2pass', $component->html());
    }

    public function test_an_unreadable_stored_password_is_reported_as_a_test_error_without_throwing(): void
    {
        $this->actAsPanelAdministrator();
        $this->storeSetting('mail.transport', 'smtp');
        $this->storeSetting('mail.smtp.host', 'smtp.provider.example');
        $this->storeSetting('mail.smtp.password', 'garbage');

        $component = Livewire::test(MailSettings::class)->call('sendTestEmail');

        $this->assertFalse($component->get('testOk'));
        $this->assertStringContainsString('enter it again', (string) $component->get('testResult'));
    }

    // ---- Check DNS ------------------------------------------------------------------------------------------

    public function test_the_dns_check_reports_what_it_sees_and_words_a_miss_as_not_seen_yet(): void
    {
        $this->actAsPanelAdministrator();
        $lookup = $this->fakeLookup([
            'shop.example' => ['v=spf1 include:spf.provider.com ~all'],
            '_dmarc.shop.example' => [],
            's1._domainkey.shop.example' => ['CNAME:s1.dkim.provider.com'],
        ]);

        $component = Livewire::test(MailSettings::class)
            ->fillForm(['from_transactional_address' => 'orders@shop.example', 'dns_include' => 'spf.provider.com', 'dns_selector' => 's1'])
            ->call('checkDns');

        $this->assertSame(['shop.example', '_dmarc.shop.example', 's1._domainkey.shop.example'], $lookup->hosts);

        $component->assertSee(__('mail.dns.spf_ok'))
            ->assertSee(__('mail.dns.spf_include_ok'))
            ->assertSee(__('mail.dns.dmarc_unseen'))
            ->assertSee(__('mail.dns.dkim_ok'))
            ->assertDontSee('broken');
    }

    public function test_a_missing_include_is_not_seen_yet(): void
    {
        $this->actAsPanelAdministrator();
        $this->fakeLookup(['shop.example' => ['v=spf1 include:other.example ~all']]);

        Livewire::test(MailSettings::class)
            ->fillForm(['from_transactional_address' => 'orders@shop.example', 'dns_include' => 'spf.provider.com'])
            ->call('checkDns')
            ->assertSee(__('mail.dns.spf_include_unseen'));
    }

    public function test_the_dns_check_without_a_valid_sender_address_does_no_lookup(): void
    {
        $this->actAsPanelAdministrator();
        $lookup = $this->fakeLookup();

        Livewire::test(MailSettings::class)
            ->fillForm(['from_transactional_address' => 'not an address', 'from_marketing_address' => ''])
            ->call('checkDns')
            ->assertSee(__('mail.dns.no_domain'));

        $this->assertSame([], $lookup->hosts);
    }

    public function test_an_invalid_selector_or_include_does_no_lookup_for_that_part(): void
    {
        $this->actAsPanelAdministrator();
        $lookup = $this->fakeLookup(['shop.example' => ['v=spf1 ~all']]);

        Livewire::test(MailSettings::class)
            ->fillForm(['from_transactional_address' => 'orders@shop.example', 'dns_include' => 'bad include/', 'dns_selector' => 'se lec/tor'])
            ->call('checkDns')
            ->assertSee(__('mail.dns.invalid'));

        $this->assertSame(['shop.example', '_dmarc.shop.example'], $lookup->hosts, 'no DKIM lookup for a bad selector');
    }

    public function test_a_failing_lookup_never_produces_a_500(): void
    {
        $this->actAsPanelAdministrator();
        $this->fakeLookup([], fn () => throw new RuntimeException('resolver exploded'));

        Livewire::test(MailSettings::class)
            ->fillForm(['from_transactional_address' => 'orders@shop.example', 'dns_selector' => 's1'])
            ->call('checkDns')
            ->assertSuccessful()
            ->assertSee(__('mail.dns.skipped'))
            ->assertDontSee('resolver exploded');
    }

    // ---- Queue health ---------------------------------------------------------------------------------------

    public function test_the_queue_line_counts_pending_mail_jobs_and_the_oldest_age_from_one_query(): void
    {
        $this->actAsPanelAdministrator();
        config(['queue.default' => 'database']);
        $now = time();

        foreach ([[ 'mail-transactional', $now - 7200], ['mail-transactional', $now - 60], ['mail-marketing', $now - 30], ['default', $now - 99999]] as [$queue, $created]) {
            DB::table('jobs')->insert(['queue' => $queue, 'payload' => '{}', 'attempts' => 0, 'available_at' => $created, 'created_at' => $created]);
        }

        $component = Livewire::test(MailSettings::class);

        $component->assertSee('Order emails: 2 waiting, oldest 2h')
            ->assertSee('News emails: 1 waiting');
    }

    public function test_the_queue_line_says_not_available_when_the_driver_is_not_database(): void
    {
        $this->actAsPanelAdministrator();
        config(['queue.default' => 'sync']);

        Livewire::test(MailSettings::class)->assertSee(__('mail.queue.not_available'));
    }
}
