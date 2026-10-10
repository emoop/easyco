<?php

namespace Tests\Feature\Mail;

use App\Filament\Pages\Settings\MailSettings;
use App\Mail\MailConfigurator;
use App\Models\ActivityLogModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

class MailSettingsPageTest extends TestCase
{
    use ActsAsMailStaff;
    use RefreshDatabase;

    private const SECRET = 'Sup3r-S3cret-Pw!';

    /** One request carrying the typed password AND the save call, exactly like the browser sends it. */
    private function saveWithPassword($component, string $password)
    {
        return $component->update(
            calls: [['method' => 'save', 'params' => [], 'path' => '']],
            updates: ['data.smtp_password' => $password],
        );
    }

    private function snapshotJson($component): string
    {
        return json_encode(\Livewire\invade($component)->lastState->getSnapshot());
    }

    private function smtpForm(array $overrides = []): array
    {
        return $overrides + [
            'transport' => 'smtp',
            'smtp_host' => 'smtp.provider.example',
            'smtp_port' => '587',
            'smtp_encryption' => 'tls',
            'smtp_username' => 'shop-user',
        ];
    }

    public function test_the_page_renders_for_an_administrator_and_says_emails_are_only_logged(): void
    {
        config(['mail.default' => 'log']);
        $this->actAsPanelAdministrator();

        Livewire::test(MailSettings::class)
            ->assertSuccessful()
            ->assertSee(__('mail.status.log'))
            ->assertSee(__('mail.status.sender_unverified'));
    }

    public function test_saving_smtp_settings_stores_them_and_encrypts_the_password(): void
    {
        $this->actAsPanelAdministrator();

        $component = Livewire::test(MailSettings::class)->fillForm($this->smtpForm());
        $this->saveWithPassword($component, self::SECRET)->assertHasNoFormErrors();

        $this->assertSame('smtp', $this->stored('mail.transport'));
        $this->assertSame('smtp.provider.example', $this->stored('mail.smtp.host'));
        $this->assertSame('587', $this->stored('mail.smtp.port'));
        $this->assertSame('shop-user', $this->stored('mail.smtp.username'));

        $storedPassword = $this->stored('mail.smtp.password');
        $this->assertNotNull($storedPassword);
        $this->assertStringNotContainsString(self::SECRET, $storedPassword);
        $this->assertSame(self::SECRET, Crypt::decryptString($storedPassword));
    }

    public function test_an_empty_password_keeps_the_stored_one_and_remove_clears_it(): void
    {
        $this->actAsPanelAdministrator();
        $this->storeSetting('mail.smtp.password', Crypt::encryptString(self::SECRET));
        $before = $this->stored('mail.smtp.password');

        $component = Livewire::test(MailSettings::class)
            ->fillForm($this->smtpForm())
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($before, $this->stored('mail.smtp.password'), 'an empty submit keeps the stored secret');
        $component->assertSee(__('mail.smtp.password_help_saved'));

        $component->call('removePassword');

        $this->assertNull($this->stored('mail.smtp.password'));
    }

    public function test_the_password_never_appears_in_html_snapshot_journal_or_log(): void
    {
        $this->actAsPanelAdministrator();
        $this->storeSetting('admin.activity_log_enabled', '1');
        Log::spy();

        $component = Livewire::test(MailSettings::class)->fillForm($this->smtpForm());
        $this->saveWithPassword($component, self::SECRET);

        $this->assertStringNotContainsString(self::SECRET, $component->html());
        $this->assertStringNotContainsString(self::SECRET, $this->snapshotJson($component));
        $this->assertNull($component->get('data.smtp_password'), 'the typed secret is dropped from the Livewire state');

        $this->assertNotSame(0, ActivityLogModel::count(), 'the change is journalled');

        foreach (ActivityLogModel::all() as $row) {
            $this->assertStringNotContainsString(self::SECRET, json_encode($row->getAttributes()));
            $this->assertNotSame(self::SECRET, $row->new_value);
        }

        $this->assertTrue(
            ActivityLogModel::where('field', 'mail.smtp.password')->where('new_value', 'changed')->whereNull('old_value')->exists(),
            'the journal records "changed", never a value',
        );

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        $this->assertStringNotContainsString(self::SECRET, (string) @file_get_contents(storage_path('logs/laravel.log')));

        // A later request, even a plain re-render with a saved password, never carries it either.
        $component->call('$refresh');
        $this->assertStringNotContainsString(self::SECRET, $component->html());
        $this->assertStringNotContainsString(self::SECRET, $this->snapshotJson($component));
    }

    public function test_a_typed_password_is_not_left_in_the_snapshot_even_when_validation_fails(): void
    {
        $this->actAsPanelAdministrator();

        $component = Livewire::test(MailSettings::class)->fillForm($this->smtpForm(['smtp_host' => 'bad host!']));
        $this->saveWithPassword($component, self::SECRET)->assertHasFormErrors(['smtp_host']);

        $this->assertStringNotContainsString(self::SECRET, $this->snapshotJson($component));
        $this->assertStringNotContainsString(self::SECRET, $component->html());
        $this->assertNull($this->stored('mail.smtp.password'));
    }

    public function test_an_undecryptable_stored_password_renders_a_re_enter_notice_without_throwing(): void
    {
        $this->actAsPanelAdministrator();
        $this->storeSetting('mail.transport', 'smtp');
        $this->storeSetting('mail.smtp.host', 'smtp.provider.example');
        $this->storeSetting('mail.smtp.password', 'not-an-encrypted-payload');

        Livewire::test(MailSettings::class)
            ->assertSuccessful()
            ->assertSee(__('mail.status.password_unreadable'))
            ->assertSee(__('mail.smtp.password_help_unreadable'))
            ->assertDontSee('not-an-encrypted-payload');
    }

    public function test_saving_with_an_unreadable_stored_password_keeps_it_until_a_new_one_is_typed(): void
    {
        $this->actAsPanelAdministrator();
        $this->storeSetting('mail.smtp.password', 'garbage');

        $component = Livewire::test(MailSettings::class)->fillForm($this->smtpForm())->call('save')->assertHasNoFormErrors();
        $this->assertSame('garbage', $this->stored('mail.smtp.password'));

        $this->saveWithPassword($component->fillForm($this->smtpForm()), self::SECRET);
        $this->assertSame(self::SECRET, Crypt::decryptString($this->stored('mail.smtp.password')));
    }

    public function test_smtp_host_port_and_password_are_validated(): void
    {
        $this->actAsPanelAdministrator();

        Livewire::test(MailSettings::class)
            ->fillForm($this->smtpForm(['smtp_host' => "evil.example\r\nRCPT TO:<x@y.z>", 'smtp_port' => '99999']))
            ->call('save')
            ->assertHasFormErrors(['smtp_host', 'smtp_port']);

        Livewire::test(MailSettings::class)
            ->fillForm($this->smtpForm(['smtp_port' => '0']))
            ->call('save')
            ->assertHasFormErrors(['smtp_port']);

        $component = Livewire::test(MailSettings::class)->fillForm($this->smtpForm());
        $this->saveWithPassword($component, "pw\r\nBcc: x@y.z")->assertHasFormErrors(['smtp_password']);
        $this->assertNull($this->stored('mail.smtp.password'));
    }

    public function test_selecting_the_env_mailer_forgets_the_transport_but_keeps_the_smtp_fields(): void
    {
        $this->actAsPanelAdministrator();
        $this->storeSetting('mail.transport', 'smtp');
        $this->storeSetting('mail.smtp.host', 'smtp.provider.example');

        Livewire::test(MailSettings::class)
            ->fillForm(['transport' => 'env'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($this->stored('mail.transport'));
        $this->assertSame('smtp.provider.example', $this->stored('mail.smtp.host'), 'switching back and forth does not lose the details');
    }

    public function test_sender_identities_are_saved_trimmed_and_the_domain_confirmation_is_a_setting(): void
    {
        $this->actAsPanelAdministrator();

        Livewire::test(MailSettings::class)
            ->fillForm([
                'transport' => 'env',
                'from_transactional_address' => ' orders@shop.example ',
                'from_transactional_name' => 'My Shop',
                'from_marketing_address' => 'news@shop.example',
                'from_marketing_name' => '',
                'reply_to' => 'info@shop.example',
                'domain_confirmed' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('orders@shop.example', $this->stored('mail.from.transactional.address'));
        $this->assertSame('My Shop', $this->stored('mail.from.transactional.name'));
        $this->assertNull($this->stored('mail.from.marketing.name'));
        $this->assertSame('info@shop.example', $this->stored('mail.reply_to'));
        $this->assertSame('1', $this->stored('mail.sender.domain_confirmed'));

        Livewire::test(MailSettings::class)->assertSee(__('mail.status.sender_verified'));
    }

    /** @return array<string, array{0: string}> */
    public static function badAddresses(): array
    {
        return [
            'two recipients' => ['a@b.co, d@e.fr'],
            'semicolon list' => ['a@b.co;d@e.fr'],
            'crlf injection' => ["a@b.co\r\nBcc: x@y.zz"],
            'lf only' => ["a@b.co\nBcc: x@y.zz"],
            'display name form' => ['Shop <a@b.co>'],
            'no at sign' => ['not-an-address'],
            'too long' => [str_repeat('a', 250).'@b.co'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badAddresses')]
    public function test_bad_sender_and_reply_to_addresses_are_refused(string $bad): void
    {
        $this->actAsPanelAdministrator();

        Livewire::test(MailSettings::class)
            ->fillForm(['transport' => 'env', 'from_transactional_address' => $bad, 'from_marketing_address' => $bad, 'reply_to' => $bad])
            ->call('save')
            ->assertHasFormErrors(['from_transactional_address', 'from_marketing_address', 'reply_to']);

        $this->assertNull($this->stored('mail.from.transactional.address'));
        $this->assertNull($this->stored('mail.reply_to'));
    }

    /** @return array<string, array{0: string}> */
    public static function badNames(): array
    {
        return [
            'crlf' => ["Shop\r\nBcc: x@y.zz"],
            'newline' => ["Shop\nName"],
            'nul' => ["Shop\0Name"],
            'tab' => ["Shop\tName"],
            'bidi override' => ["Shop \u{202E}gnp.exe"],
            'bidi isolate' => ["Shop \u{2066}x"],
            'zero width' => ["Shop\u{200B}Name"],
            'line separator' => ["Shop\u{2028}Name"],
            'over 80' => [str_repeat('x', 81)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badNames')]
    public function test_bad_display_names_are_refused(string $bad): void
    {
        $this->actAsPanelAdministrator();

        Livewire::test(MailSettings::class)
            ->fillForm(['transport' => 'env', 'from_transactional_name' => $bad, 'from_marketing_name' => $bad])
            ->call('save')
            ->assertHasFormErrors(['from_transactional_name', 'from_marketing_name']);

        $this->assertNull($this->stored('mail.from.transactional.name'));
    }

    public function test_a_80_character_name_with_non_latin_letters_is_accepted(): void
    {
        $this->actAsPanelAdministrator();

        Livewire::test(MailSettings::class)
            ->fillForm(['transport' => 'env', 'from_transactional_name' => str_repeat('Я', 80)])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(str_repeat('Я', 80), $this->stored('mail.from.transactional.name'));
    }

    public function test_the_status_line_names_smtp_when_it_is_configured(): void
    {
        $this->actAsPanelAdministrator();
        $this->storeSetting('mail.transport', 'smtp');
        $this->storeSetting('mail.smtp.host', 'smtp.provider.example');
        $this->storeSetting('mail.smtp.port', '465');

        Livewire::test(MailSettings::class)->assertSee('SMTP configured: smtp.provider.example, port 465.');
    }

    public function test_the_mail_help_topic_exists_in_both_languages_with_the_anchor_the_page_links_to(): void
    {
        foreach (['en', 'bg'] as $locale) {
            $help = app(\App\Filament\Support\HelpRenderer::class)->render('mail', $locale);

            $this->assertSame($locale, $help['locale'], 'no fallback: both files exist');
            $this->assertStringContainsString('id="action-mail-settings"', $help['html']);
            $this->assertStringContainsString('p=none', $help['html']);
            $this->assertStringContainsString('--queue=mail-transactional,default,mail-marketing', $help['html']);
            $this->assertStringContainsString('v=DMARC1', $help['html']);
        }

        $this->actAsPanelAdministrator();
        Livewire::test(MailSettings::class)->assertSee(\App\Filament\Support\HelpLink::url('mail_settings', 'mail'), false);
    }

    public function test_saved_settings_are_what_the_configurator_uses_at_send_time(): void
    {
        $this->actAsPanelAdministrator();

        $component = Livewire::test(MailSettings::class)->fillForm($this->smtpForm(['smtp_encryption' => 'ssl', 'smtp_port' => '465']));
        $this->saveWithPassword($component, self::SECRET);

        $sender = app(MailConfigurator::class)->apply('transactional');

        $this->assertSame('easyco', $sender->mailer);
        $this->assertSame(self::SECRET, config('mail.mailers.easyco.password'));
        $this->assertSame('smtps', config('mail.mailers.easyco.scheme'));
        $this->assertSame(465, config('mail.mailers.easyco.port'));
    }
}
