<?php

namespace EasyCo\Media\Image;

/**
 * What MetadataStripper::strip() found and did (ML-0): the new bytes, the format, what was removed (segment / chunk kinds, with the bytes they held), and the flags of the cases that are not a
 * plain lossless strip. A value object: the caller decides what to write.
 */
final class StripResult
{
    /**
     * @param  string  $bytes  the bytes to store (the input itself when nothing changed)
     * @param  string  $format  `jpeg` | `png` | `webp` | `gif` | `converted` (re-encoded to JPEG) | `video` | `unknown`
     * @param  list<string>  $removed  the kinds removed, in file order (e.g. `APP1`, `tEXt`, `EXIF`, `XMP `, `comment`)
     * @param  int  $removedBytes  the bytes those segments held
     * @param  bool  $malformed  the structure could not be walked: the input is returned unchanged
     * @param  bool  $reencoded  the picture was decoded and encoded again to keep its EXIF orientation (the only lossy case of a lossless format)
     * @param  bool  $converted  the format is not handled losslessly and the picture was re-encoded as JPEG (HEIC, AVIF ...)
     * @param  int  $orientation  the EXIF Orientation (2-8) that made the picture be turned upright, 1 when there was none
     * @param  bool  $needsConversion  the format is not handled losslessly and conversion was not asked for: the input is returned unchanged
     */
    public function __construct(
        public readonly string $bytes,
        public readonly string $format,
        public readonly array $removed = [],
        public readonly int $removedBytes = 0,
        public readonly bool $malformed = false,
        public readonly bool $reencoded = false,
        public readonly bool $converted = false,
        public readonly bool $needsConversion = false,
        public readonly bool $changed = false,
        public readonly int $orientation = 1,
    ) {
    }

    /** The file extension the stored file should have for this format (null = keep the one the client sent). */
    public function extension(): ?string
    {
        return match ($this->format) {
            'jpeg', 'converted' => 'jpg',
            'png', 'webp', 'gif' => $this->format,
            default => null,
        };
    }
}
