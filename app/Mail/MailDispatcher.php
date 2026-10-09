<?php

namespace App\Mail;

use App\Settings\StoreLocale;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queues a mail: the `mail_log` row is inserted FIRST (the idempotency guard), then the job is dispatched
 * (mail-design.md §6). A duplicate key means "already queued or sent" and stops silently.
 *
 * If the dispatch itself fails (the queue is down) the reserved row is removed again, so the reconciliation
 * (mail:reconcile-order-confirmations) finds the order without a row and queues it later. Nothing here may
 * ever throw into a checkout or an admin action: callers catch Throwable too.
 */
class MailDispatcher
{
    public function __construct(
        private readonly MailLog $log,
        private readonly MailTemplates $templates,
        private readonly StoreLocale $locale,
    ) {
    }

    /** @return bool true when a new mail was queued */
    public function queueOrderConfirmation(string $orderId, string $email): bool
    {
        $definition = $this->templates->get(MailTemplates::ORDER_CONFIRMATION);
        $key = $definition->key.':'.$orderId;
        $recipient = MailHeader::address($email);

        $id = $this->log->reserve([
            'template_key' => $definition->key,
            'idempotency_key' => $key,
            'category' => $definition->category,
            // An unusable address is recorded as skipped (no job), so it is visible and never retried.
            'to_email' => $recipient ?? mb_substr(MailHeader::clean($email, 254), 0, 254),
            'locale' => $this->locale->current(),
            'related_type' => 'order',
            'related_id' => $orderId,
            'status' => $recipient === null ? MailLog::SKIPPED : MailLog::QUEUED,
            'last_error' => $recipient === null ? 'invalid_recipient' : null,
        ]);

        if ($id === null || $recipient === null) {
            return false;
        }

        try {
            Bus::dispatch(new SendMailJob($id, $definition->queue));
        } catch (Throwable $e) {
            $this->log->release($id);
            Log::error('mail.dispatch_failed', ['template' => $definition->key, 'related_id' => $orderId, 'exception' => $e::class]);

            return false;
        }

        return true;
    }
}
