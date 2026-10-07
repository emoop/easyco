<?php

namespace App\Services;

/**
 * The "add X more for free shipping" fact the storefront shows (shipping stage
 * 3e, shipping-domain-design.md §5.1): a plain sentence plus the numbers it was
 * built from, computed on the SAME basis the free-shipping threshold itself uses
 * — goods AFTER the discount. It is INFORMATION, never a rule: it blocks
 * nothing, changes no price, and a null hint is a normal state (nothing to say).
 *
 * One of two states:
 *  - REMAINING: a threshold-carrying method is not yet unlocked; `remainingMinor`
 *    is how much more the customer must spend, and `text` says so, naming the
 *    method.
 *  - UNLOCKED: no method is still short, but one is already free by its
 *    threshold; `remainingMinor` is 0 and `text` states the method is free.
 */
final class FreeShippingHint
{
    public const REMAINING = 'remaining';

    public const UNLOCKED = 'unlocked';

    public function __construct(
        public readonly string $state,
        public readonly string $methodId,
        public readonly string $methodName,
        public readonly int $freeAboveMinor,
        public readonly int $remainingMinor,
        public readonly string $currency,
        public readonly string $text,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'method_id' => $this->methodId,
            'method_name' => $this->methodName,
            'free_above_minor' => $this->freeAboveMinor,
            'remaining_minor' => $this->remainingMinor,
            'currency' => $this->currency,
            'text' => $this->text,
        ];
    }
}
