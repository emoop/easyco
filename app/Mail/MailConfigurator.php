<?php

namespace App\Mail;

use App\Mail\Transports\MailTransports;
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
 * The admin page that writes these is `App\Filament\Pages\Settings\MailSettings` (stage M2); this class only reads
 * them. The transports are a registry (MailTransports): `mail.transport` empty or `env` = the .env mailer.
 */
final class MailConfigurator
{
    public const MAILER = 'easyco';

    /** @var list<string> */
    private array $revealed = [];

    public function __construct(
        private readonly SiteSettingsRepository $settings,
        private readonly MailTransports $transports = new MailTransports(),
    ) {
    }

    /**
     * @param int|null $timeout socket timeout in seconds for an SMTP transport; null keeps the transport's own
     *                          (the synchronous "send test email" passes 10, a queued job never needs to)
     *
     * @throws MailConfigurationException
     */
    public function apply(string $sender, ?int $timeout = null): MailerSettings
    {
        $mailer = (string) config('mail.default');
        $chosen = $this->value('mail.transport');

        if ($chosen !== null && $chosen !== MailTransports::ENV) {
            $transport = $this->transports->find($chosen)
                ?? throw new MailConfigurationException('The selected mail transport is not available.');

            config(['mail.mailers.'.self::MAILER => $transport->mailerConfig(
                fn (string $key): ?string => $this->value($key),
                fn (string $key): ?string => $this->secret($key),
            )]);
            $mailer = self::MAILER;
        }

        if ($timeout !== null && config('mail.mailers.'.$mailer.'.transport') === 'smtp') {
            config(['mail.mailers.'.$mailer.'.timeout' => $timeout]);
        }

        if ($mailer === self::MAILER || $timeout !== null) {
            // The manager caches mailer instances for the life of the worker: drop it so a changed setting is used.
            Mail::purge($mailer);
        }

        $address = MailHeader::address((string) $this->settings->get('mail.from.'.$sender.'.address'))
            ?? MailHeader::address((string) config('mail.from.address'))
            ?? 'hello@example.com';

        $name = MailHeader::clean((string) ($this->value('mail.from.'.$sender.'.name') ?? config('mail.from.name') ?? config('app.name')), 80);

        return new MailerSettings($mailer, $address, $name, MailHeader::address((string) $this->settings->get('mail.reply_to')));
    }

    /**
     * Whether a stored secret exists and can be read: `none`, `saved` or `unreadable` (APP_KEY changed). The admin
     * page shows this state; the secret itself never leaves this class except into the mailer config at send time.
     */
    public function secretState(string $key): string
    {
        try {
            return $this->secret($key) === null ? 'none' : 'saved';
        } catch (MailConfigurationException) {
            return 'unreadable';
        }
    }

    /**
     * The secrets decrypted so far by this instance, so an error text can have them removed (MailErrors::sanitise).
     *
     * @return list<string>
     */
    public function revealedSecrets(): array
    {
        return $this->revealed;
    }

    /** A stored secret, decrypted. Only ever called while a transport builds its config, at send time. */
    private function secret(string $key): ?string
    {
        $stored = $this->value($key);

        if ($stored === null) {
            return null;
        }

        try {
            $secret = Crypt::decryptString($stored);
            $this->revealed[] = $secret;

            return $secret;
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
