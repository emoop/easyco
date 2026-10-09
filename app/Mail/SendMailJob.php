<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Sends ONE mail whose `mail_log` row already exists (mail-design.md §4, §6). It carries only the row's id:
 * the recipient, the locale and the related order are read from the row, so nothing a person typed is
 * ever serialised into the queue payload.
 *
 * STATUS MACHINE: the job claims the row with a conditional UPDATE (queued -> sending), so a retried job,
 * a duplicate dispatch or a second worker finds it taken and stops. A transient failure puts the row back
 * to `queued` and rethrows (the queue retries with the backoff below); a permanent failure marks it `failed`
 * and ends without retry. When the retries are used up, failed() marks it `failed`.
 *
 * THE TRANSPORT IS RESOLVED HERE, not at boot (MailConfigurator) — a persistent worker keeps its booted state.
 * After deploying a change to this class, `php artisan queue:restart` is required (CLAUDE.md).
 */
final class SendMailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Transactional policy of mail-design.md §4. */
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(
        public readonly int $mailLogId,
        string $queue = 'mail-transactional',
    ) {
        $this->onQueue($queue);
    }

    public function handle(MailLog $log, MailTemplates $templates, MailConfigurator $configurator, OrderConfirmationContent $orderConfirmation): void
    {
        $row = $log->find($this->mailLogId);

        if ($row === null || ! $log->claim($this->mailLogId)) {
            return;
        }

        try {
            $recipient = MailHeader::address((string) $row->to_email) ?? throw new InvalidRecipientException('The recipient address is not valid.');
            $definition = $templates->get((string) $row->template_key);
            $rendered = $this->content($row, $orderConfirmation);

            if ($rendered === null) {
                $log->markSkipped($this->mailLogId, 'source_missing');

                return;
            }

            $sender = $configurator->apply($definition->sender);

            Mail::mailer($sender->mailer)->to($recipient)->send(new RenderedMailable($rendered, $sender));

            $log->markSent($this->mailLogId, $rendered->subject);
        } catch (Throwable $e) {
            $description = MailErrors::describe($e);

            if (MailErrors::isPermanent($e)) {
                $log->markFailed($this->mailLogId, $description);
                Log::warning('mail.send_failed_permanently', ['mail_log_id' => $this->mailLogId, 'reason' => $description]);

                return;
            }

            $log->markRetryable($this->mailLogId, $description);

            // A fresh exception: the original (a transport's) is not re-serialised into failed_jobs with its trace.
            throw new RuntimeException($description);
        }
    }

    /** The retries are used up. */
    public function failed(Throwable $e): void
    {
        app(MailLog::class)->markFailed($this->mailLogId, MailErrors::describe($e));
    }

    private function content(stdClass $row, OrderConfirmationContent $orderConfirmation): ?RenderedMail
    {
        return match ($row->template_key) {
            MailTemplates::ORDER_CONFIRMATION => $orderConfirmation->build($row),
            default => throw new TemplateException('No content builder for this template.'),
        };
    }
}
