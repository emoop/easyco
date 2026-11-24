<?php

namespace App\Media;

use Carbon\CarbonInterface;

/**
 * MEDIA-UP-3: the ONE rule for «a picture that is still waiting for the queue»: `pending` / `processing`, its file not marked missing, and uploaded less than MINUTES ago. The queue finishes in about a
 * second; a row that waits longer is stuck (a stopped worker, a lost job), and then nothing on the screen may poll or turn for ever: the picker modal, the picture field of a form and the «Медия» page all ask this
 * class, so they stop together. Reads the row already loaded: no query.
 */
final class ProcessingWindow
{
    /** How long after its upload a picture that is not ready yet is still «обработва се» (polls, spins). */
    public const MINUTES = 5;

    /** @param  object{processing_status: mixed, missing_at: mixed, created_at: mixed}  $row */
    public static function waiting(object $row): bool
    {
        $createdAt = $row->created_at;

        return in_array($row->processing_status, ['pending', 'processing'], true)
            && $row->missing_at === null
            && $createdAt instanceof CarbonInterface
            && $createdAt->gt(now()->subMinutes(self::MINUTES));
    }
}
