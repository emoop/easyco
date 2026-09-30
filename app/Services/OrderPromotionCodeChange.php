<?php

namespace App\Services;

use InvalidArgumentException;
use LogicException;

/**
 * What one order edit does to the applied promotion code — THREE states,
 * never a bare `?string` (order-editing-design.md §1/§7), because a nullable
 * string cannot tell "leave the code alone" from "remove it": both would be
 * null.
 *
 *   unchanged()   keep whatever the order has (possibly nothing); the
 *                 discount is still recomputed over the resulting lines
 *   set($code)    apply this code — a replacement when the order already
 *                 has one, an addition when it has none
 *   removed()     drop the applied code; releases its redemption
 *
 * SHAPE: three static factories on one final class with a private
 * constructor — the shape App\Services\OrderRefundOutcome already
 * establishes for "one result, a few named states". Chosen over a PHP enum
 * because the SET state carries a payload (the code), which a backed enum
 * case cannot; and over an enum wrapped in a class because that would give
 * the same three states two homes.
 */
final class OrderPromotionCodeChange
{
    private function __construct(
        private readonly string $state,
        private readonly ?string $code,
    ) {}

    public static function unchanged(): self
    {
        return new self('unchanged', null);
    }

    public static function set(string $code): self
    {
        if (trim($code) === '') {
            throw new InvalidArgumentException('OrderPromotionCodeChange::set() requires a non-empty promotion code; use removed() to drop the code.');
        }

        return new self('set', $code);
    }

    public static function removed(): self
    {
        return new self('removed', null);
    }

    public function isUnchanged(): bool
    {
        return $this->state === 'unchanged';
    }

    public function isSet(): bool
    {
        return $this->state === 'set';
    }

    public function isRemoved(): bool
    {
        return $this->state === 'removed';
    }

    /** The code to apply — only meaningful for set(). */
    public function code(): string
    {
        if ($this->code === null) {
            throw new LogicException('OrderPromotionCodeChange::code() is only defined for a set() change.');
        }

        return $this->code;
    }
}
