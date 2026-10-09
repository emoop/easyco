<?php

namespace App\Mail;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Access to `mail_log` (mail-design.md §6): the idempotency record and the status machine
 * `queued -> sending -> sent|failed|skipped`.
 *
 * The body is never stored. `last_error` is already sanitised by the caller (MailErrors::describe()).
 */
final class MailLog
{
    public const QUEUED = 'queued';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    /** A `sending` row older than this is a crashed worker's, and may be claimed again. */
    public const STALE_SENDING_MINUTES = 15;

    /**
     * Inserts the row FIRST. Null means the idempotency key already exists — "already queued or sent":
     * the caller stops silently. The collision is recognised by SQLSTATE 23000 and the driver's own code
     * (MySQL 1062, SQLite 19), never by message text (CLAUDE.md rule 3). Any other error propagates.
     *
     * @param  array{template_key: string, idempotency_key: string, category: string, to_email: string, locale: string, related_type?: ?string, related_id?: ?string, status?: string, last_error?: ?string}  $attributes
     */
    public function reserve(array $attributes): ?int
    {
        $now = now();

        try {
            return (int) DB::table('mail_log')->insertGetId([
                'template_key' => $attributes['template_key'],
                'idempotency_key' => $attributes['idempotency_key'],
                'category' => $attributes['category'],
                'to_email' => $attributes['to_email'],
                'locale' => $attributes['locale'],
                'status' => $attributes['status'] ?? self::QUEUED,
                'attempts' => 0,
                'last_error' => $attributes['last_error'] ?? null,
                'related_type' => $attributes['related_type'] ?? null,
                'related_id' => $attributes['related_id'] ?? null,
                'queued_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null;
            }

            throw $e;
        }
    }

    public function find(int $id): ?stdClass
    {
        return DB::table('mail_log')->where('id', $id)->first();
    }

    /**
     * Claims the row for sending with ONE conditional UPDATE, so two workers cannot send the same mail:
     * only the worker whose UPDATE changed a row proceeds. A `queued` row is claimable; so is a `sending`
     * row left behind by a crashed worker (older than STALE_SENDING_MINUTES).
     */
    public function claim(int $id): bool
    {
        $now = now();

        $changed = DB::table('mail_log')
            ->where('id', $id)
            ->where(function ($query) use ($now): void {
                $query->where('status', self::QUEUED)
                    ->orWhere(function ($stale) use ($now): void {
                        $stale->where('status', self::SENDING)
                            ->where('updated_at', '<', $now->copy()->subMinutes(self::STALE_SENDING_MINUTES));
                    });
            })
            ->update([
                'status' => self::SENDING,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => $now,
            ]);

        return $changed === 1;
    }

    public function markSent(int $id, string $subject): void
    {
        $now = now();

        DB::table('mail_log')->where('id', $id)->where('status', self::SENDING)->update([
            'status' => self::SENT,
            'subject' => $subject,
            'last_error' => null,
            'sent_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** A transient failure: back to `queued` so the retry can claim it again. */
    public function markRetryable(int $id, string $error): void
    {
        DB::table('mail_log')->where('id', $id)->where('status', self::SENDING)->update([
            'status' => self::QUEUED,
            'last_error' => $error,
            'updated_at' => now(),
        ]);
    }

    /** A permanent failure (or the last retry used up). */
    public function markFailed(int $id, string $error): void
    {
        DB::table('mail_log')->where('id', $id)->whereIn('status', [self::SENDING, self::QUEUED])->update([
            'status' => self::FAILED,
            'last_error' => $error,
            'updated_at' => now(),
        ]);
    }

    public function markSkipped(int $id, string $reason): void
    {
        DB::table('mail_log')->where('id', $id)->whereIn('status', [self::SENDING, self::QUEUED])->update([
            'status' => self::SKIPPED,
            'last_error' => $reason,
            'updated_at' => now(),
        ]);
    }

    /** Removes a row whose job was never dispatched (the queue was down), so the reconciliation can queue it again. */
    public function release(int $id): void
    {
        DB::table('mail_log')->where('id', $id)->where('status', self::QUEUED)->where('attempts', 0)->delete();
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;

        return $e->getCode() === '23000' && in_array($driverCode, [1062, 19], true);
    }
}
