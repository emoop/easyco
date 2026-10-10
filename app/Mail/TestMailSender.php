<?php

namespace App\Mail;

use App\Mail\Transports\SmtpTransport;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The admin's "Send test email" (mail-design.md §2): SYNCHRONOUS, 10-second socket timeout, through MailConfigurator
 * exactly like a real mail, never queued, never written to `mail_log` (so Needs-attention can never count a test as
 * a failed order mail). Returns null on success or the transport's real error text, sanitised.
 */
final class TestMailSender
{
    public const TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly MailConfigurator $configurator,
        private readonly SiteSettingsRepository $settings,
    ) {
    }

    public function send(string $to): ?string
    {
        $recipient = MailHeader::address($to);

        if ($recipient === null) {
            return __('mail.validation.address');
        }

        try {
            $sender = $this->configurator->apply('transactional', self::TIMEOUT_SECONDS);
            $subject = MailHeader::clean(__('mail.test_email.subject'), 180);
            $body = __('mail.test_email.body');
            $rendered = new RenderedMail($subject, '<p>'.e($body).'</p>', $body);

            Mail::mailer($sender->mailer)->to($recipient)->send(new RenderedMailable($rendered, $sender));

            return null;
        } catch (Throwable $e) {
            // The password (decrypted by the configurator for this very send) and the username are removed from the text.
            $secrets = [...$this->configurator->revealedSecrets(), (string) $this->settings->get(SmtpTransport::USERNAME)];

            return MailErrors::sanitise(MailErrors::describe($e), $secrets);
        }
    }
}
