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
 * payment() on a replay is the Payment row AS THE DATABASE HOLDS IT (shipping
 * stage 4c: it used to be null, which hid a PENDING payment from a customer who
 * retried after a failed payment step). A replay still never re-charges and never
 * writes; payment() is null only if the order somehow has no payment row. On a
 * fresh placement it is the row the attempt was recorded on — or, when the payment
 * step threw after the commit, the row reloaded from the database (PENDING, no
 * attempt date).
 *
 * paymentNeedsAttention() is true only on a fresh placement whose payment step
 * threw after the commit (§9.1.5): the order, the PENDING payment and the claimed
 * cart stand, and the controller tells the customer not to place it again.
 */
final class CheckoutResult
{
    private function __construct(
        private readonly Order $order,
        private readonly bool $alreadyPlaced,
        private readonly ?Payment $payment,
        private readonly bool $paymentNeedsAttention = false,
    ) {
    }

    public static function placed(Order $order, ?Payment $payment = null, bool $paymentNeedsAttention = false): self
    {
        return new self($order, false, $payment, $paymentNeedsAttention);
    }

    public static function alreadyPlaced(Order $order, ?Payment $payment = null): self
    {
        return new self($order, true, $payment);
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

    public function paymentNeedsAttention(): bool
    {
        return $this->paymentNeedsAttention;
    }
}
