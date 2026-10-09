<?php

namespace App\Mail;

use EasyCo\Order\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The `order.placed` listener (mail-design.md §6.1): it only DISPATCHES. It runs after the order transaction has
 * committed and must not throw — stage 4c contains a throwing listener at the call site anyway, but this one does not
 * even try. Idempotent through the mail_log key: a replayed checkout never reaches the hook, and a double event
 * queues nothing the second time.
 *
 * Registered in AppServiceProvider (domain packages never call Hook:: themselves).
 */
final class SendOrderConfirmation
{
    public function __construct(
        private readonly MailDispatcher $dispatcher,
    ) {
    }

    public function handle(Order $order): void
    {
        try {
            $this->dispatcher->queueOrderConfirmation((string) $order->id(), $order->email());
        } catch (Throwable $e) {
            Log::error('mail.order_confirmation_listener_failed', ['order_id' => $order->id(), 'exception' => $e::class]);
        }
    }
}
