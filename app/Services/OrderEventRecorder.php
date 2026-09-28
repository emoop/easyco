<?php

namespace App\Services;

use App\Enums\OrderEventType;
use App\Models\OrderEventModel;
use DateTimeImmutable;
use EasyCo\Order\Enums\OrderStatus;
use InvalidArgumentException;

/**
 * The one writer of order_events — order-lifecycle-design.md §6.2, §10 stage 3.
 * It writes a fact; it decides nothing. Which event a transition records, when
 * it is recorded and what the reason says belong to the callers §10 stages 4-6
 * build (OrderStatusChanger, OrderPaymentConfirmer, OrderRefunder), so nothing
 * in production calls this class yet — it is infrastructure, complete and
 * tested on its own.
 *
 * ALWAYS ON. There is no Site Setting gate and no early return anywhere in this
 * class: §0 item 8 is the entire reason the table exists (ActivityLogger writes
 * nothing unless `admin.activity_log_enabled` is '1' and its rows are pruned
 * after `admin.activity_log_retention_months`; an order's history may be
 * neither). No prune command and no retention setting touch this table either
 * (§11 item 1).
 *
 * INSERT ONLY. The single statement this class ever issues is one INSERT into
 * order_events: never an update, never a delete, and no read of its own.
 *
 * IT DOES NOT OPEN A TRANSACTION, AND IT MUST NOT. §6.2: the recorder is called
 * from INSIDE the caller's transaction, so a transition that rolls back leaves
 * no event behind — the event is part of the same fact, not a notification
 * about it. That is also why this class has no `DB::transaction()` of its own:
 * one would commit (or roll back) work that belongs to the caller.
 *
 * THE ACTOR IS RESOLVED, NEVER PASSED — through App\Services\PanelStaffActor,
 * the same single resolution ActivityLogger uses (CLAUDE.md: one rule, one
 * home). staff_id and staff_name are both NULL for a console or queued caller
 * rather than being an error (§6.2, §11 item 16: no caller passes a staff id,
 * and no service-level permission check exists).
 *
 * THE $type PARAMETER IS THE ENUM, AND §6.2's OWN SIGNATURE SAYS `string $type`
 * — a deliberate, reported difference: the enum is what makes the six values
 * exhaustive in one place, what the label parity test can walk
 * (OrderEventTypeLabelsTest), and what stops a caller writing a type no label
 * exists for. The stored column stays a plain string (§6.1), and the value
 * written is `$type->value`, so the table is byte-identical to what §6.1
 * describes.
 *
 * NOTE_ADDED, the one ADDITION beyond §6.1's type list (owner decision D5): an
 * internal note an operator left on the order, recorded as an event so the
 * order's own timeline is the one place a merchant reads "what happened here".
 * It is enforced here rather than trusted to callers: note_added carries no
 * status change at all and a note that cannot be read is not a note, so both
 * guards are fail-loud InvalidArgumentExceptions, never a silently mangled row.
 */
final class OrderEventRecorder
{
    public function __construct(
        private readonly PanelStaffActor $staffActor,
    ) {
    }

    /**
     * Records one event against $orderId.
     *
     * `$fromStatus`/`$toStatus` are both set for a transition, and BOTH NULL for
     * every event that did not move the status (§6.1) — a partial return, a
     * payment confirmation, a void, a note. Half a pair is refused rather than
     * guessed at.
     *
     * `$reason` is the operator's own words, stored verbatim and untranslated. A
     * blank or whitespace-only reason on any type other than NOTE_ADDED is
     * stored as NULL — "nothing was said" and "someone typed spaces" are the
     * same fact, and §11 item 7 makes every reason optional.
     *
     * `$transactionId`, when given, points at the return's own Transaction whose
     * REFUND lines are the per-line record (§6.4: the event is a signpost, never
     * a second home for the numbers). The FK is real: an unknown id fails at the
     * engine rather than writing a dangling pointer.
     *
     * `$occurredAt` is the instant the fact happened, supplied by the caller
     * (§6.1) — not "now", and never this class's own DateTimeImmutable.
     *
     * @throws InvalidArgumentException If only one of the two statuses is set,
     *   or if a NOTE_ADDED event carries a status change or a blank note.
     */
    public function record(
        string $orderId,
        OrderEventType $type,
        ?OrderStatus $fromStatus,
        ?OrderStatus $toStatus,
        ?string $reason,
        ?string $transactionId,
        DateTimeImmutable $occurredAt,
    ): void {
        if (($fromStatus === null) !== ($toStatus === null)) {
            throw new InvalidArgumentException(
                'OrderEventRecorder: from_status and to_status must be both set (a transition) or both NULL '.
                '(every event that moved no status) — one half of the pair is not a state this table can hold.'
            );
        }

        if ($type === OrderEventType::NOTE_ADDED) {
            if ($fromStatus !== null || $toStatus !== null) {
                throw new InvalidArgumentException(
                    'OrderEventRecorder: a note_added event carries no status change — from_status and to_status must both be NULL.'
                );
            }

            if (trim((string) $reason) === '') {
                throw new InvalidArgumentException(
                    'OrderEventRecorder: a note_added event requires a non-blank reason — the note itself is its whole content.'
                );
            }
        }

        $staff = $this->staffActor->current();

        OrderEventModel::create([
            'order_id' => $orderId,
            'type' => $type->value,
            'from_status' => $fromStatus?->value,
            'to_status' => $toStatus?->value,
            'reason' => trim((string) $reason) === '' ? null : $reason,
            'transaction_id' => $transactionId,
            'staff_id' => $staff?->id,
            'staff_name' => $staff?->name,
            'occurred_at' => $occurredAt,
        ]);
    }
}
