<?php

namespace Tests\Feature\Mail;

use App\Mail\MailConfigurationException;
use App\Mail\MailConfigurator;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class MailConfiguratorTest extends TestCase
{
    use RefreshDatabase;

    private function set(string $key, string $value): void
    {
        app(SiteSettingsRepository::class)->set($key, $value);
    }

    public function test_with_no_settings_the_env_mailer_and_sender_are_used_unchanged(): void
    {
        config(['mail.default' => 'array', 'mail.from.address' => 'hello@example.com', 'mail.from.name' => 'EasyCo']);

        $settings = app(MailConfigurator::class)->apply('transactional');

        $this->assertSame('array', $settings->mailer);
        $this->assertSame('hello@example.com', $settings->fromAddress);
        $this->assertSame('EasyCo', $settings->fromName);
        $this->assertNull($settings->replyTo);
        $this->assertNull(config('mail.mailers.easyco'));
    }

    public function test_the_sender_identities_and_reply_to_come_from_the_settings_and_are_cleaned(): void
    {
        $this->set('mail.from.transactional.address', 'orders@shop.example');
        $this->set('mail.from.transactional.name', "My Shop\r\nBcc: spy@example.com");
        $this->set('mail.from.marketing.address', 'news@shop.example');
        $this->set('mail.reply_to', 'info@shop.example');

        $transactional = app(MailConfigurator::class)->apply('transactional');
        $marketing = app(MailConfigurator::class)->apply('marketing');

        $this->assertSame('orders@shop.example', $transactional->fromAddress);
        $this->assertStringNotContainsString("\n", $transactional->fromName);
        $this->assertStringNotContainsString("\r", $transactional->fromName);
        $this->assertSame('info@shop.example', $transactional->replyTo);
        $this->assertSame('news@shop.example', $marketing->fromAddress);
    }

    public function test_an_invalid_sender_address_falls_back_instead_of_reaching_a_header(): void
    {
        config(['mail.from.address' => 'hello@example.com']);
        $this->set('mail.from.transactional.address', "a@example.com\r\nBcc: b@example.com");

        $this->assertSame('hello@example.com', app(MailConfigurator::class)->apply('transactional')->fromAddress);
    }

    public function test_smtp_settings_build_the_easyco_mailer_inside_the_job_call_with_a_decrypted_password(): void
    {
        $this->set('mail.transport', 'smtp');
        $this->set('mail.smtp.host', 'smtp.provider.example');
        $this->set('mail.smtp.port', '465');
        $this->set('mail.smtp.encryption', 'ssl');
        $this->set('mail.smtp.username', 'user');
        $this->set('mail.smtp.password', Crypt::encryptString('s3cret'));

        $settings = app(MailConfigurator::class)->apply('transactional');

        $this->assertSame('easyco', $settings->mailer);
        $config = config('mail.mailers.easyco');
        $this->assertSame('smtp.provider.example', $config['host']);
        $this->assertSame(465, $config['port']);
        $this->assertSame('smtps', $config['scheme']);
        $this->assertSame('s3cret', $config['password']);
    }

    public function test_an_unreadable_stored_password_says_to_re_enter_it_without_leaking_anything(): void
    {
        $this->set('mail.transport', 'smtp');
        $this->set('mail.smtp.host', 'smtp.provider.example');
        $this->set('mail.smtp.password', 'not-an-encrypted-payload-s3cret');

        try {
            app(MailConfigurator::class)->apply('transactional');
            $this->fail('An unreadable password must be reported.');
        } catch (MailConfigurationException $e) {
            $this->assertStringContainsString('enter it again', $e->getMessage());
            $this->assertStringNotContainsString('s3cret', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function test_a_bad_host_or_port_is_refused(): void
    {
        $this->set('mail.transport', 'smtp');
        $this->set('mail.smtp.host', "evil.example\r\nRCPT TO:<x@y.z>");

        $this->expectException(MailConfigurationException::class);
        app(MailConfigurator::class)->apply('transactional');
    }

    public function test_a_bad_port_is_refused(): void
    {
        $this->set('mail.transport', 'smtp');
        $this->set('mail.smtp.host', 'smtp.provider.example');
        $this->set('mail.smtp.port', '99999');

        $this->expectException(MailConfigurationException::class);
        app(MailConfigurator::class)->apply('transactional');
    }
}
