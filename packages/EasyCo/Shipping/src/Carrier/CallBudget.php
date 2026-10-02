<?php

namespace EasyCo\Shipping\Carrier;

use EasyCo\Shipping\Exceptions\InvalidCarrierDataException;

/**
 * How long a provider may take to answer a rate or pickup-point call
 * (shipping-domain-design.md §6, failure tolerance).
 *
 * PHP cannot interrupt arbitrary code, so the budget is not enforced from the
 * outside: it is HANDED to the provider, and every implementation is OBLIGED to
 * honour it — typically as the timeout of its own HTTP client (connect and read
 * together never longer than milliseconds()). The caller (CarrierCallGuard)
 * only measures: an answer that arrives after the budget is discarded and
 * logged as an overrun.
 */
final class CallBudget
{
    public const MAX_MILLISECONDS = 60_000;

    private function __construct(private readonly int $milliseconds)
    {
        if ($milliseconds < 1 || $milliseconds > self::MAX_MILLISECONDS) {
            throw InvalidCarrierDataException::field(self::class, 'milliseconds', 'must be between 1 and '.self::MAX_MILLISECONDS);
        }
    }

    public static function milliseconds(int $milliseconds): self
    {
        return new self($milliseconds);
    }

    public function inMilliseconds(): int
    {
        return $this->milliseconds;
    }

    /** For HTTP clients that take seconds (e.g. Guzzle's `timeout`). */
    public function inSeconds(): float
    {
        return $this->milliseconds / 1000;
    }
}
