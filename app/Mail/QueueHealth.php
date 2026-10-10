<?php

namespace App\Mail;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pending mail jobs and the age of the oldest, per mail queue, from ONE grouped query on the `jobs` table
 * (mail-design.md §4). Null when the queue driver is not `database` or the table cannot be read ("not available").
 */
final class QueueHealth
{
    public const QUEUES = ['mail-transactional', 'mail-marketing'];

    /** @return array<string, array{pending: int, oldest_age_seconds: ?int}>|null */
    public function snapshot(): ?array
    {
        $connection = (string) config('queue.default');

        if (config('queue.connections.'.$connection.'.driver') !== 'database') {
            return null;
        }

        $table = (string) config('queue.connections.'.$connection.'.table', 'jobs');

        try {
            $rows = DB::table($table)
                ->whereIn('queue', self::QUEUES)
                ->groupBy('queue')
                ->selectRaw('queue, COUNT(*) as pending, MIN(created_at) as oldest')
                ->get();
        } catch (Throwable) {
            return null;
        }

        $snapshot = array_fill_keys(self::QUEUES, ['pending' => 0, 'oldest_age_seconds' => null]);

        foreach ($rows as $row) {
            $snapshot[(string) $row->queue] = [
                'pending' => (int) $row->pending,
                'oldest_age_seconds' => $row->oldest === null ? null : max(0, time() - (int) $row->oldest),
            ];
        }

        return $snapshot;
    }
}
