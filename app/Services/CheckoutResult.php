<?php

namespace App\Services;

use EasyCo\Order\Order;

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
 */
final class CheckoutResult
{
    private function __construct(
        private readonly Order $order,
        private readonly bool $alreadyPlaced,
    ) {
    }

    public static function placed(Order $order): self
    {
        return new self($order, false);
    }

    public static function alreadyPlaced(Order $order): self
    {
        return new self($order, true);
    }

    public function order(): Order
    {
        return $this->order;
    }

    public function isAlreadyPlaced(): bool
    {
        return $this->alreadyPlaced;
    }
}
