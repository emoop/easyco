<?php

namespace App\Media;

use App\Services\ActivityLogger;
use EasyCo\Media\Contracts\MediaAssetRepository;
use Illuminate\Validation\ValidationException;

/**
 * Saves the alt text of an asset (ML-1a «Медия» details, ML-2 media picker): plain text (no control or bidi-override characters), at most 200, empty = none; through the domain
 * repository; the log line says which asset, never the text. The CALLER checks who may do it.
 */
final class MediaAltText
{
    public const MAX = 200;

    /** @throws ValidationException on the `$field` when the text is not plain or too long */
    public static function save(int $id, mixed $value, string $field = 'alt_text'): void
    {
        $text = is_string($value) ? trim($value) : '';

        if (mb_strlen($text) > self::MAX || preg_match('/[\x00-\x1F\x7F-\x9F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $text) !== 0) {
            throw ValidationException::withMessages([$field => __('media.library.details.alt_invalid')]);
        }

        $repository = app(MediaAssetRepository::class);
        $asset = $repository->findById((string) $id);

        if ($asset === null) {
            return;
        }

        $new = $text === '' ? null : $text;

        // nothing to write (and nothing to log) when the text is the one the asset has
        if ($asset->altText() === $new) {
            return;
        }

        $asset->updateAltText($new);
        $repository->save($asset);

        app(ActivityLogger::class)->logFieldChanged('media_asset', (string) $id, 'alt_text', null, 'saved');
    }
}
