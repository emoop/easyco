<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\OrderEditor::apply() when the order's real
 * edit_revision is no longer the one the edit form was built from
 * (order-editing-design.md §5 step 3, D5's compare-and-set) — another
 * operator edited the order in the meantime ("two operators, one tab"), or
 * the same submission was retried after it already succeeded. Refused
 * before anything else runs: no stock touched, no line written.
 *
 * Carries both revisions as VALUES, not only inside the sentence — the same
 * convention InvalidOrderTransitionException and OrderNotEditableException
 * follow — so the admin UI can reload the form at actualRevision() without
 * parsing a message. RuntimeException for the same reason those two use it:
 * a fact about this order under concurrency, not a programming error.
 */
final class StaleOrderEditException extends RuntimeException
{
    public function __construct(
        private readonly string $orderId,
        private readonly int $expectedRevision,
        private readonly int $actualRevision,
    ) {
        parent::__construct(
            "Order \"{$orderId}\" has been edited since this edit began: it was built from revision {$expectedRevision}, but the order is now at revision {$actualRevision}."
        );
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    /** The revision the refused edit was built from. */
    public function expectedRevision(): int
    {
        return $this->expectedRevision;
    }

    /** The order's real revision when the edit was refused. */
    public function actualRevision(): int
    {
        return $this->actualRevision;
    }
}
