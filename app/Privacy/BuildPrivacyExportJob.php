<?php

namespace App\Privacy;

use App\Privacy\Persistence\PrivacyRequestModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Builds the ZIP of one export request (acc-4-gdpr-design.md §4, ACC-4c): exactly `data.html` + `data.json` (PrivacyExportBuilder), made in a TEMP file and stored on the PRIVATE `local` disk
 * (`storage/app/private`, never under public/) as `privacy-exports/{uuid}.zip`. The uuid is random: the path cannot be guessed, and nothing links to it outside the staff-only download.
 *
 * On success the row is `done`, with `export_path`, `export_expires_at` (+ EXPIRES_DAYS), `completed_at` and `clear_until` (+ the clear-view days: the existing `privacy:expire-clear-view` then
 * nulls the clear email). On failure it is `failed` (also when the worker kills a job that ran too long: `failed()`), the half-made file and the temp file are removed, and the clear email
 * still ages out.
 *
 * QUEUE (incident DEP-3, 2026-11-22): `default`, a queue the compose `queue` worker serves (tests/Feature/Deploy/QueueWorkerQueuesTest). A job on a queue nobody serves never runs and never
 * fails. `$tries = 1` (a retry of a failure only repeats it); `$timeout` stays below the worker's `--timeout` (QUEUE_WORKER_TIMEOUT, 90 s) and far below DB_QUEUE_RETRY_AFTER.
 */
final class BuildPrivacyExportJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public const QUEUE = 'default';

    public const DIRECTORY = 'privacy-exports';

    public const EXPIRES_DAYS = 7;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly int $requestId,
    ) {
        $this->onQueue(self::QUEUE);
        $this->afterCommit();
    }

    public function handle(PrivacyExportBuilder $builder): void
    {
        $request = PrivacyRequestModel::query()->find($this->requestId);

        // a request that is gone, or not waiting for its build, is not built twice
        if ($request === null || $request->kind !== PrivacyRequestModel::KIND_EXPORT || $request->status !== PrivacyRequestModel::STATUS_PROCESSING || blank($request->email)) {
            return;
        }

        $path = null;

        try {
            $files = $builder->build((string) $request->email);
            $path = self::DIRECTORY.'/'.Str::uuid()->toString().'.zip';
            $this->zip($files);

            $stream = fopen($this->tempPath(), 'rb');

            try {
                if ($stream === false || ! Storage::disk('local')->writeStream($path, $stream)) {
                    throw new RuntimeException('The export could not be stored.');
                }
            } finally {
                is_resource($stream) && fclose($stream);
            }

            $now = now();
            $request->update([
                'status' => PrivacyRequestModel::STATUS_DONE,
                'export_path' => $path,
                'export_expires_at' => $now->copy()->addDays(self::EXPIRES_DAYS),
                'completed_at' => $now,
                'clear_until' => $now->copy()->addDays((int) config('privacy.clear_view_days')),
            ]);
        } catch (Throwable $e) {
            $path !== null && Storage::disk('local')->delete($path);
            $this->markFailed();

            throw $e;
        } finally {
            $this->removeTemp();
        }
    }

    /** The worker calls this when the job throws or is killed on its timeout (`handle`'s own catch does not run then). */
    public function failed(?Throwable $exception = null): void
    {
        $this->markFailed();
        $this->removeTemp();

        // never the email or the data: the class and the request id only
        Log::error('privacy.export_failed', ['request_id' => $this->requestId, 'exception' => $exception === null ? null : $exception::class]);
    }

    /** @param array{html: string, json: string} $files */
    private function zip(array $files): void
    {
        $zip = new ZipArchive();

        if ($zip->open($this->tempPath(), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The export archive could not be created.');
        }

        $zip->addFromString('data.html', $files['html']);
        $zip->addFromString('data.json', $files['json']);

        if (! $zip->close()) {
            throw new RuntimeException('The export archive could not be written.');
        }
    }

    private function markFailed(): void
    {
        PrivacyRequestModel::query()->whereKey($this->requestId)->where('status', PrivacyRequestModel::STATUS_PROCESSING)->update([
            'status' => PrivacyRequestModel::STATUS_FAILED,
            'clear_until' => now()->addDays((int) config('privacy.clear_view_days')),
        ]);
    }

    /** One deterministic temp path per request, so `failed()` finds what a killed run left behind. */
    private function tempPath(): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'privacy-export-'.$this->requestId.'.zip';
    }

    private function removeTemp(): void
    {
        is_file($this->tempPath()) && @unlink($this->tempPath());
    }
}
