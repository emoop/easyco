<?php

namespace App\Mail;

use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/**
 * Resolves the transport and the sender for one mail (mail-design.md §2, §3).
 *
 * APPLIED INSIDE THE JOB, NEVER AT BOOT: a persistent queue worker boots the app once and keeps that
 * state (CLAUDE.md), so a setting read at boot would be stale until the next restart. The settings
 * repository is a scoped binding, which the worker resets between jobs.
 *
 * `mail.*` settings, all optional; with none of them set the .env mailer (`MAIL_MAILER`, `log` in
 * development) and `MAIL_FROM_*` are used unchanged:
 *   mail.transport                         'smtp' selects the settings below
 *   mail.smtp.host / port / encryption     encryption: tls | ssl | none
 *   mail.smtp.username
 *   mail.smtp.password                     ENCRYPTED with Crypt (APP_KEY); never logged, never echoed
 *   mail.from.{transactional|marketing}.address / .name
 *   mail.reply_to
 *
 * The admin page that writes these is stage M2; this class only reads them.
 */
final class MailConfigurator
{
    public const MAILER = 'easyco';

    public function __construct(
        private readonly SiteSettingsRepository $settings,
    ) {
    }

    /** @throws MailConfigurationException */
    public function apply(string $sender): MailerSettings
    {
        $mailer = (string) config('mail.default');

        if ($this->value('mail.transport') === 'smtp') {
            config(['mail.mailers.'.self::MAILER => $this->smtp()]);
            // The manager caches mailer instances for the life of the worker: drop it so a changed setting is used.
            Mail::purge(self::MAILER);
            $mailer = self::MAILER;
        }

        $address = MailHeader::address((string) $this->settings->get('mail.from.'.$sender.'.address'))
            ?? MailHeader::address((string) config('mail.from.address'))
            ?? 'hello@example.com';

        $name = MailHeader::clean((string) ($this->value('mail.from.'.$sender.'.name') ?? config('mail.from.name') ?? config('app.name')), 80);

        return new MailerSettings($mailer, $address, $name, MailHeader::address((string) $this->settings->get('mail.reply_to')));
    }

    /** @return array<string, mixed> */
    private function smtp(): array
    {
        $host = $this->value('mail.smtp.host') ?? '';

        if (preg_match('/^[A-Za-z0-9]([A-Za-z0-9.\-]{0,251}[A-Za-z0-9])?$/', $host) !== 1) {
            throw new MailConfigurationException('The SMTP host is not valid.');
        }

        $port = $this->value('mail.smtp.port') ?? '587';

        if (! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new MailConfigurationException('The SMTP port is not valid.');
        }

        $encryption = $this->value('mail.smtp.encryption') ?? 'tls';

        return [
            'transport' => 'smtp',
            'host' => $host,
            'port' => (int) $port,
            // "ssl" = implicit TLS (smtps); "tls" negotiates STARTTLS when the server offers it.
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'username' => $this->value('mail.smtp.username'),
            'password' => $this->password(),
            'timeout' => 15,
        ];
    }

    private function password(): ?string
    {
        $stored = $this->value('mail.smtp.password');

        if ($stored === null) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            // An APP_KEY change makes stored secrets unreadable: say so, without the value.
            throw new MailConfigurationException('The stored mail password cannot be read; enter it again.');
        }
    }

    private function value(string $key): ?string
    {
        $value = trim((string) $this->settings->get($key));

        return $value === '' ? null : $value;
    }
}
