<?php

namespace Tests\Unit\Mail;

use App\Mail\MailConfigurationException;
use App\Mail\MailConfigurator;
use App\Mail\Transports\MailTransports;
use App\Mail\Transports\SmtpTransport;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/** Effective-mailer resolution against an in-memory settings repository: no database. */
class MailConfiguratorResolutionTest extends TestCase
{
    private function configurator(array $settings): MailConfigurator
    {
        $repository = new class($settings) implements SiteSettingsRepository {
            public int $reads = 0;

            public function __construct(private array $values)
            {
            }

            public function get(string $key): ?string
            {
                $this->reads++;

                return $this->values[$key] ?? null;
            }

            public function set(string $key, string $value): void
            {
                $this->values[$key] = $value;
            }

            public function forget(string $key): void
            {
                unset($this->values[$key]);
            }
        };

        return new MailConfigurator($repository);
    }

    public function test_empty_settings_resolve_to_the_env_mailer_and_build_no_easyco_mailer(): void
    {
        config(['mail.default' => 'array', 'mail.mailers.easyco' => null]);

        $this->assertSame('array', $this->configurator([])->apply('transactional')->mailer);
        $this->assertNull(config('mail.mailers.easyco'));
    }

    public function test_the_explicit_env_value_is_the_same_as_empty(): void
    {
        config(['mail.default' => 'log', 'mail.mailers.easyco' => null]);

        $this->assertSame('log', $this->configurator(['mail.transport' => 'env', 'mail.smtp.host' => 'smtp.provider.example'])->apply('transactional')->mailer);
        $this->assertNull(config('mail.mailers.easyco'));
    }

    public function test_smtp_resolves_to_the_easyco_mailer_with_the_stored_values_and_a_decrypted_password(): void
    {
        $configurator = $this->configurator([
            'mail.transport' => 'smtp',
            'mail.smtp.host' => 'smtp.provider.example',
            'mail.smtp.port' => '2525',
            'mail.smtp.encryption' => 'none',
            'mail.smtp.username' => 'user',
            'mail.smtp.password' => Crypt::encryptString('p4ss'),
        ]);

        $this->assertSame('easyco', $configurator->apply('transactional')->mailer);

        $config = config('mail.mailers.easyco');
        $this->assertSame('smtp', $config['transport']);
        $this->assertSame('smtp.provider.example', $config['host']);
        $this->assertSame(2525, $config['port']);
        $this->assertSame('smtp', $config['scheme']);
        $this->assertSame('user', $config['username']);
        $this->assertSame('p4ss', $config['password']);
        $this->assertSame(15, $config['timeout']);
        $this->assertSame(['p4ss'], $configurator->revealedSecrets());
    }

    public function test_the_stored_value_is_ciphertext_and_only_the_configurator_turns_it_back(): void
    {
        $stored = Crypt::encryptString('p4ss');

        $this->assertStringNotContainsString('p4ss', $stored);
        $this->assertSame('saved', $this->configurator(['mail.smtp.password' => $stored])->secretState(SmtpTransport::PASSWORD));
    }

    public function test_the_secret_state_is_none_saved_or_unreadable_and_never_throws(): void
    {
        $this->assertSame('none', $this->configurator([])->secretState(SmtpTransport::PASSWORD));
        $this->assertSame('unreadable', $this->configurator(['mail.smtp.password' => 'plaintext-s3cret'])->secretState(SmtpTransport::PASSWORD));
    }

    public function test_an_unknown_transport_is_a_configuration_error_not_a_silent_fallback(): void
    {
        $this->expectException(MailConfigurationException::class);

        $this->configurator(['mail.transport' => 'carrier-pigeon'])->apply('transactional');
    }

    public function test_the_test_send_timeout_is_applied_to_the_smtp_mailer(): void
    {
        $configurator = $this->configurator(['mail.transport' => 'smtp', 'mail.smtp.host' => 'smtp.provider.example']);

        $configurator->apply('transactional', 10);

        $this->assertSame(10, config('mail.mailers.easyco.timeout'));
    }

    public function test_the_registry_lists_smtp_and_describes_its_fields_including_one_secret(): void
    {
        $transports = new MailTransports();

        $this->assertSame(['smtp'], array_keys($transports->all()));
        $this->assertNull($transports->find('env'));
        $this->assertNull($transports->find(null));

        $secrets = array_filter($transports->find('smtp')->fields(), fn ($field) => $field->isSecret());
        $this->assertCount(1, $secrets);
        $this->assertSame('mail.smtp.password', reset($secrets)->settingKey);
    }
}
