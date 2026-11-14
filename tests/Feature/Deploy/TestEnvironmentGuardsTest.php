<?php

namespace Tests\Feature\Deploy;

use App\Mail\MailConfigurator;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/** DOCKER-1 B: the three test-environment switches (config/easyco.php). ON does it, OFF changes nothing. */
class TestEnvironmentGuardsTest extends TestCase
{
    use RefreshDatabase;

    // ---- no indexing ---------------------------------------------------------------------------------------------------------

    public function test_noindex_on_sets_the_header_and_disallows_everything(): void
    {
        config(['easyco.noindex' => true]);

        $home = $this->get('/');
        $this->assertSame('noindex, nofollow', $home->headers->get('X-Robots-Tag'));

        $robots = $this->get('/robots.txt');
        $robots->assertOk();
        $this->assertSame("User-agent: *\nDisallow: /\n", $robots->getContent());
        $this->assertSame('noindex, nofollow', $robots->headers->get('X-Robots-Tag'));
    }

    public function test_noindex_off_changes_nothing_and_robots_is_the_file_byte_for_byte(): void
    {
        config(['easyco.noindex' => false]);

        $this->assertNull($this->get('/')->headers->get('X-Robots-Tag'));

        $robots = $this->get('/robots.txt');
        $robots->assertOk();
        $this->assertSame(file_get_contents(public_path('robots.txt')), $robots->getContent());
        $this->assertNull($robots->headers->get('X-Robots-Tag'));
    }

    // ---- site password -------------------------------------------------------------------------------------------------------

    public function test_basic_auth_on_refuses_without_or_with_wrong_credentials_and_accepts_the_right_ones(): void
    {
        config(['easyco.basic_auth.user' => 'tester', 'easyco.basic_auth.password' => 's3cret']);

        $refused = $this->get('/');
        $refused->assertStatus(401);
        $this->assertStringContainsString('Basic', (string) $refused->headers->get('WWW-Authenticate'));

        $this->withHeader('Authorization', 'Basic '.base64_encode('tester:wrong'))->get('/')->assertStatus(401);
        $this->withHeader('Authorization', 'Basic '.base64_encode('other:s3cret'))->get('/')->assertStatus(401);
        $this->get('/admin/login')->assertStatus(401);

        $this->withHeader('Authorization', 'Basic '.base64_encode('tester:s3cret'))->get('/')->assertStatus(200);
    }

    public function test_basic_auth_keeps_the_health_route_open(): void
    {
        config(['easyco.basic_auth.user' => 'tester', 'easyco.basic_auth.password' => 's3cret']);

        $this->get('/up')->assertOk();
    }

    public function test_basic_auth_needs_both_values_and_off_changes_nothing(): void
    {
        $plain = $this->get('/');
        $plain->assertOk();

        config(['easyco.basic_auth.user' => 'tester', 'easyco.basic_auth.password' => '']);
        $this->get('/')->assertOk();

        config(['easyco.basic_auth.user' => '', 'easyco.basic_auth.password' => 's3cret']);
        $second = $this->get('/');
        $second->assertOk();

        $this->assertSame($plain->getContent(), $second->getContent(), 'with the switches off the page is byte-for-byte the same');
    }

    // ---- mail sandbox --------------------------------------------------------------------------------------------------------

    private function adminConfiguredSmtp(): void
    {
        $settings = app(SiteSettingsRepository::class);
        $settings->set('mail.transport', 'smtp');
        $settings->set('mail.smtp.host', 'smtp.provider.example');
        $settings->set('mail.smtp.port', '465');
        $settings->set('mail.smtp.encryption', 'ssl');
        $settings->set('mail.smtp.username', 'user');
        $settings->set('mail.smtp.password', Crypt::encryptString('s3cret'));
    }

    public function test_mail_sandbox_overrides_the_transport_the_admin_configured(): void
    {
        $this->adminConfiguredSmtp();
        config(['easyco.mail_sandbox.enabled' => true, 'easyco.mail_sandbox.host' => 'mailpit', 'easyco.mail_sandbox.port' => 1025]);

        $settings = app(MailConfigurator::class)->apply('transactional');

        $this->assertSame(MailConfigurator::SANDBOX_MAILER, $settings->mailer);
        $this->assertSame('mailpit', config('mail.mailers.easyco-sandbox.host'));
        $this->assertSame(1025, config('mail.mailers.easyco-sandbox.port'));
        $this->assertNull(config('mail.mailers.easyco-sandbox.password'));
        $this->assertNull(config('mail.mailers.easyco'), 'the admin transport is not even built');

        $withTimeout = app(MailConfigurator::class)->apply('transactional', 10);
        $this->assertSame(MailConfigurator::SANDBOX_MAILER, $withTimeout->mailer);
        $this->assertSame(10, config('mail.mailers.easyco-sandbox.timeout'));
    }

    public function test_mail_sandbox_off_keeps_the_admin_transport(): void
    {
        $this->adminConfiguredSmtp();
        config(['easyco.mail_sandbox.enabled' => false]);

        $settings = app(MailConfigurator::class)->apply('transactional');

        $this->assertSame(MailConfigurator::MAILER, $settings->mailer);
        $this->assertSame('smtp.provider.example', config('mail.mailers.easyco.host'));
        $this->assertNull(config('mail.mailers.easyco-sandbox'));
    }
}
