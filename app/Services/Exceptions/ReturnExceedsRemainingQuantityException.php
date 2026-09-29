<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\ReturnGoodsRecorder::record() when a line's own
 * requested quantityReturned exceeds what R7's own read
 * (order-lifecycle-design.md §2.2) says actually remains — the originating
 * SaleLine's own quantity minus what prior REFUND lines already cover.
 *
 * CARRIES THE FACTS AS VALUES, NOT ONLY INSIDE A SENTENCE — the same shape
 * every other named exception in this project's app layer follows for a
 * refusal a future caller may want to render or branch on
 * (EasyCo\Order\Exceptions\InvalidOrderTransitionException's own docblock
 * states the same reasoning).
 */
final class ReturnExceedsRemainingQuantityException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $originatingSaleLineId,
        private readonly int $requestedQuantity,
        private readonly int $remainingQuantity,
    ) {
        parent::__construct($message);
    }

    public static function forLine(string $originatingSaleLineId, int $requestedQuantity, int $remainingQuantity): self
    {
        return new self(
            "Cannot return {$requestedQuantity} unit(s) of SaleLine \"{$originatingSaleLineId}\": only {$remainingQuantity} unit(s) remain.",
            $originatingSaleLineId,
            $requestedQuantity,
            $remainingQuantity,
        );
    }

    public function originatingSaleLineId(): string
    {
        return $this->originatingSaleLineId;
    }

    public function requestedQuantity(): int
    {
        return $this->requestedQuantity;
    }

    public function remainingQuantity(): int
    {
        return $this->remainingQuantity;
    }
}
