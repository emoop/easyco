<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * ACC-1: sends ONE customer-account mail (verification, reset link, ...). Unlike the order confirmation (whose content is read from the order, so the queue
 * payload holds only an id), these mails carry a one-time link: the link, the address and the locale travel in the job, so the payload is ENCRYPTED
 * (ShouldBeEncrypted) and never written to mail_log or the logs. The text is rendered here, through the same TemplateRenderer / sanitizer / MailConfigurator /
 * RenderedMailable as every other mail of the shop. Nothing in this class logs an address or a link: a failure is described by MailErrors::describe() (URLs and
 * addresses scrubbed).
 *
 * After deploying a change to this class, `php artisan queue:restart` is required (CLAUDE.md).
 */
final class SendAccountMailJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    /**
     * @param  array<string, array{url: string, label: string}>  $links  block name => the absolute URL and the `mail.account.*` label key
     */
    public function __construct(
        public readonly string $templateKey,
        public readonly string $email,
        public readonly string $locale,
        public readonly array $links = [],
        string $queue = 'mail-transactional',
    ) {
        $this->onQueue($queue);
    }

    public function handle(MailTemplates $templates, TemplateRenderer $renderer, MailConfigurator $configurator): void
    {
        $recipient = MailHeader::address($this->email);

        if ($recipient === null) {
            Log::warning('mail.account_invalid_recipient', ['template' => $this->templateKey]);

            return;
        }

        try {
            $definition = $templates->get($this->templateKey);
            $blocks = [];

            foreach ($this->links as $name => $link) {
                $blocks[$name] = MailBlocks::link($link['url'], (string) __('mail.account.'.$link['label'], [], $this->locale), $this->locale);
            }

            $rendered = $renderer->render($definition, $this->locale, ['shop_name' => MailHeader::clean((string) config('app.name'), 80)], $blocks);
            $sender = $configurator->apply($definition->sender);

            Mail::mailer($sender->mailer)->to($recipient)->send(new RenderedMailable($rendered, $sender));
        } catch (Throwable $e) {
            $description = MailErrors::describe($e);

            if (MailErrors::isPermanent($e)) {
                Log::warning('mail.account_send_failed_permanently', ['template' => $this->templateKey, 'reason' => $description]);

                return;
            }

            // A fresh exception: the original (a transport's) is not re-serialised into failed_jobs with its trace.
            throw new RuntimeException($description);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::warning('mail.account_send_failed', ['template' => $this->templateKey, 'reason' => MailErrors::describe($e)]);
    }
}
