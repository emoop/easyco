<?php

namespace EasyCo\Media;

/**
 * Plain facts about a stored original (ML-1a): the client's file name, mime, size, pixel size and who uploaded it. All nullable: an old row has none until `media:backfill-meta`.
 * Framework-free data; the values describe the STORED bytes (after the metadata strip), never the upload as it came.
 */
final class MediaDetails
{
    public const NAME_MAX = 200;

    public function __construct(
        public readonly ?string $originalName = null,
        public readonly ?string $mime = null,
        public readonly ?int $sizeBytes = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?int $uploadedBy = null,
    ) {
    }

    /** A file name as plain text (PlainText semantics): control and bidi-override characters removed, invalid UTF-8 dropped, trimmed, at most NAME_MAX characters; null when nothing is left. */
    public static function cleanName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = (string) mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        $name = (string) preg_replace('/[\x00-\x1F\x7F-\x9F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name);
        $name = mb_substr(trim($name), 0, self::NAME_MAX);

        return $name === '' ? null : $name;
    }
}
