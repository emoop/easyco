<?php

namespace EasyCo\Media\Storage;

use EasyCo\Media\MediaDetails;
use Illuminate\Support\Facades\Cache;

/**
 * An admin form stores the bytes when the file is picked (the form state keeps only the PATH) and creates the asset row when the form is saved. So the facts known at upload (client name,
 * uploader, size, pixels) wait here, by disk and path, for a short while; the repository takes them when it inserts the row. Nothing found = the row has no details until the backfill.
 */
final class UploadDetailsStash
{
    private const TTL_HOURS = 48;

    public static function put(string $disk, string $path, MediaDetails $details): void
    {
        try {
            Cache::put(self::key($disk, $path), (array) $details, now()->addHours(self::TTL_HOURS));
        } catch (\Throwable) {
            // a cache that is down must never fail an upload; the row is described by the backfill later
        }
    }

    public static function take(string $disk, string $path): ?MediaDetails
    {
        try {
            $data = Cache::pull(self::key($disk, $path));
        } catch (\Throwable) {
            return null;
        }

        return is_array($data) ? new MediaDetails($data['originalName'] ?? null, $data['mime'] ?? null, $data['sizeBytes'] ?? null, $data['width'] ?? null, $data['height'] ?? null, $data['uploadedBy'] ?? null) : null;
    }

    private static function key(string $disk, string $path): string
    {
        return 'media.upload-details.'.md5($disk.'|'.$path);
    }
}
