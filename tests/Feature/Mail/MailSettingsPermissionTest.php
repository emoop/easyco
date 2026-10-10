<?php

namespace Tests\Feature\Mail;

use App\Filament\Pages\Settings\MailSettings;
use App\Mail\Dns\DnsLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** The page and EVERY public action need settings_manage: the Manager role has none (see BankTransferSettingsPageTest). */
class MailSettingsPermissionTest extends TestCase
{
    use ActsAsMailStaff;
    use RefreshDatabase;

    public function test_a_user_without_settings_manage_cannot_open_the_page(): void
    {
        $this->actingAs($this->staffWithRole('Manager'), 'staff');

        $this->assertFalse(MailSettings::canAccess());

        Livewire::test(MailSettings::class)->assertForbidden();
    }

    public function test_an_administrator_can(): void
    {
        $this->actAsPanelAdministrator();

        $this->assertTrue(MailSettings::canAccess());
    }

    /** @return array<string, array{0: string, 1: list<mixed>}> */
    public static function actions(): array
    {
        return [
            'save' => ['save', []],
            'removePassword' => ['removePassword', []],
            'sendTestEmail' => ['sendTestEmail', []],
            'checkDns' => ['checkDns', []],
        ];
    }

    /**
     * The attack: the browser calls a public Livewire method directly. The component was mounted while the user
     * still had the permission; the next request comes from a user who does not.
     *
     * @param list<mixed> $params
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('actions')]
    public function test_every_public_method_checks_the_permission_itself_not_only_filaments_hydrate_hook(string $method, array $params): void
    {
        $this->actAsPanelAdministrator();
        $this->storeSetting('mail.smtp.password', Crypt::encryptString('keep-me'));
        $before = $this->stored('mail.smtp.password');

        $component = Livewire::test(MailSettings::class)
            ->fillForm(['transport' => 'smtp', 'smtp_host' => 'attacker.example', 'test_recipient' => 'x@y.zz', 'from_transactional_address' => 'a@b.co']);
        $page = $component->instance();

        $this->actingAs($this->staffWithRole('Manager'), 'staff');
        Mail::fake();

        // Straight on the object: Filament's own check (run when a request hydrates the component) is not in play.
        try {
            $page->{$method}(...$params);
            $this->fail("{$method} must refuse a user without settings_manage.");
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertNull($this->stored('mail.transport'));
        $this->assertSame($before, $this->stored('mail.smtp.password'));
        Mail::assertNothingSent();
    }

    /**
     * @param list<mixed> $params
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('actions')]
    public function test_every_action_refuses_a_user_without_settings_manage_and_changes_nothing(string $method, array $params): void
    {
        $this->actAsPanelAdministrator();
        $this->storeSetting('mail.smtp.password', Crypt::encryptString('keep-me'));
        $before = $this->stored('mail.smtp.password');

        $component = Livewire::test(MailSettings::class)
            ->fillForm(['transport' => 'smtp', 'smtp_host' => 'attacker.example', 'test_recipient' => 'x@y.zz', 'from_transactional_address' => 'a@b.co']);

        $this->actingAs($this->staffWithRole('Manager'), 'staff');
        Mail::fake();
        $lookup = new class implements DnsLookup {
            public int $calls = 0;

            public function lookup(string $host, bool $includeCname = false): array
            {
                $this->calls++;

                return [];
            }
        };
        $this->app->instance(DnsLookup::class, $lookup);

        $component->call($method, ...$params)->assertForbidden();

        $this->assertNull($this->stored('mail.transport'));
        $this->assertNull($this->stored('mail.smtp.host'));
        $this->assertSame($before, $this->stored('mail.smtp.password'));
        Mail::assertNothingSent();
        $this->assertSame(0, $lookup->calls, 'no lookup for an unauthorised caller');
    }
}
