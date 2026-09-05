<?php

namespace App\Services;

use EasyCo\Order\Order;
use EasyCo\Payment\Payment;

/**
 * The outcome of CheckoutOrchestrator::place(). isAlreadyPlaced() ===
 * true means this call was an idempotent replay of an already-completed
 * checkout (a double-clicked "pay" button), NOT a failure — the returned
 * Order is the real, original one, per checkout-domain-design.md §6.
 *
 * NAMED ::alreadyPlaced() (static factory) VS isAlreadyPlaced()
 * (instance query) — deliberately different names, not a typo: PHP does
 * not allow a static and an instance method to share one name in the
 * same class, so the instance getter takes the isXxx() prefix already
 * used elsewhere in this codebase (Promotion::isActive(),
 * PromotionValidationResult::isValid()).
 *
 * payment() IS NULL ONLY ON AN IDEMPOTENT REPLAY (isAlreadyPlaced() ===
 * true) — a freshly placed order always has a real Payment (§8.3 step
 * 13 always produces one, even a FAILED one). ::alreadyPlaced() never
 * looks up the original order's existing Payment row: the replay path's
 * job is to hand back the same Order idempotently, not to re-report a
 * charge the caller already saw on the original call.
 * EasyCo\Payment\Contracts\PaymentRepository::findByOrderId() exists for
 * anyone who genuinely needs the attempt history.
 */
final class CheckoutResult
{
    private function __construct(
        private readonly Order $order,
        private readonly bool $alreadyPlaced,
        private readonly ?Payment $payment,
    ) {
    }

    public static function placed(Order $order, ?Payment $payment = null): self
    {
        return new self($order, false, $payment);
    }

    public static function alreadyPlaced(Order $order): self
    {
        return new self($order, true, null);
    }

    public function order(): Order
    {
        return $this->order;
    }

    public function isAlreadyPlaced(): bool
    {
        return $this->alreadyPlaced;
    }

    public function payment(): ?Payment
    {
        return $this->payment;
    }
}
