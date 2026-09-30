<?php

namespace App\Services;

use App\Enums\OrderEventType;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * order-lifecycle-design.md §8.1's "Add internal note" (its own §10 stage
 * 7c-3) — the View page's third write, and the one action there that
 * cannot refuse: records a single `note_added` event and moves no status.
 *
 * A DEDICATED ONE-LINE SERVICE, RATHER THAN A TRANSACTION OPENED INLINE
 * INSIDE OrderResource's OWN ACTION CLOSURE — for the SAME reason every
 * other write action on this page already has one (§8.3 item 3: "the
 * action calls the service and opens no transaction of its own ... one
 * action, one service call, one order, one transaction"). App\Services\
 * OrderEventRecorder::record() itself deliberately does not open a
 * transaction (it assumes a caller's own — see that class's own
 * docblock), so something has to; keeping that something a real service
 * rather than `DB::transaction()` inline in the Resource is what keeps
 * §8.3 item 3 true for all six actions uniformly, not five of them.
 *
 * NO PERMISSION CHECK AND NO ACTOR PARAMETER — like every other app
 * service here: the action that calls this is what is authorized
 * (ORDER_MANAGE), and the actor is resolved inside OrderEventRecorder
 * from the panel guard, so a console or queued caller records a null
 * actor rather than failing (§6.2, §11 item 16).
 */
final class OrderNoteRecorder
{
    public function __construct(
        private readonly OrderEventRecorder $events,
    ) {}

    /**
     * @throws \InvalidArgumentException If $note is blank (or whitespace-only —
     *   OrderEventRecorder::record()'s own NOTE_ADDED guard, not re-validated
     *   here; see OrderResource::addNoteAction()'s own docblock for why a
     *   whitespace-only note can still reach this guard despite the field's
     *   own ->required()).
     */
    public function record(string $orderId, string $note, DateTimeImmutable $occurredAt): void
    {
        DB::transaction(function () use ($orderId, $note, $occurredAt): void {
            $this->events->record(
                orderId: $orderId,
                type: OrderEventType::NOTE_ADDED,
                fromStatus: null,
                toStatus: null,
                reason: $note,
                transactionId: null,
                occurredAt: $occurredAt,
            );
        });
    }
}
