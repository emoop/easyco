<?php

namespace EasyCo\Media\Image;

use EasyCo\Media\Exceptions\ImageNotCleanableException;
use Illuminate\Support\Facades\Image;
use Throwable;

/**
 * Removes the METADATA from an image original (ML-0, media-library-design.md ML-D8): EXIF (with its GPS position, the camera, the time), XMP, IPTC and comments. The format is found by
 * the CONTENT (magic bytes), never by the file name. Plain byte walking, no re-encode, no new dependency:
 *
 *   JPEG  walks the markers and the scans. Dropped: APP1 (EXIF and XMP), APP13 (IPTC / Photoshop), COM, and every other APP segment except the three kept: APP0 (JFIF), APP2 AS AN ICC
 *         COLOUR PROFILE (the MPF segment and any other APP2 go) and APP14 (Adobe: needed to decode CMYK / YCCK correctly). Every non-APP marker stays. The picture ENDS at its first real
 *         EOI (stuffed bytes, restart markers and the several scans of a progressive file are walked, never cut): everything after it is dropped: the pictures a phone appends (MPF: HDR gain
 *         map, depth, each with its own EXIF and GPS) and a Motion Photo's video.
 *   PNG   walks the chunks. Dropped whole (so no CRC is recomputed): tEXt, zTXt, iTXt, eXIf, tIME, and whatever follows IEND. Everything else stays (iCCP, sRGB, gAMA, cHRM, pHYs, the animation chunks).
 *   WebP  drops the EXIF and XMP chunks, clears their two flags in VP8X, rewrites the RIFF size and cuts the bytes beyond it. ICCP, ANIM / ANMF, ALPH, VP8 / VP8L and unknown chunks stay.
 *   GIF   drops comment extensions and application extensions except NETSCAPE2.0 / ANIMEXTS1.0 (the loop); that drops the XMP "XMP DataXMP" block; bytes after the trailer go.
 *
 * ONE case is not lossless: a JPEG or WebP whose EXIF says the picture is turned (Orientation other than 1). Stripping would lose that and the picture would show rotated, so ONLY then it is
 * decoded, turned upright, encoded again (quality 95, same format) and stripped. HEIC / AVIF and every other format that decodes on this server is encoded as JPEG (quality 95, upright);
 * one that cannot be decoded is refused (ImageNotCleanableException). Video and what is not a picture pass through untouched.
 *
 * A structure that cannot be walked (a length that overruns the file) is returned UNCHANGED and flagged `malformed`: a broken file is never written.
 */
final class MetadataStripper
{
    /** The quality of a re-encode (orientation, conversion). */
    public const QUALITY = 95;

    /** The ftyp brands of still pictures (HEIC / AVIF): not video. */
    private const IMAGE_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1', 'avif', 'avis'];

    /** The APP segments that stay in a JPEG: JFIF, the ICC profile, Adobe. */
    private const KEEP_APP = [0xE0, 0xE2, 0xEE];

    /** @param  bool  $convert  false = do not re-encode HEIC / AVIF and the like (the command cannot rename a stored file): the result is flagged `needsConversion` and unchanged */
    public function strip(string $bytes, bool $convert = true): StripResult
    {
        return match ($this->detect($bytes)) {
            'jpeg' => $this->jpeg($bytes),
            'png' => $this->png($bytes),
            'webp' => $this->webp($bytes),
            'gif' => $this->gif($bytes),
            'video' => new StripResult($bytes, 'video'),
            'heif' => $convert ? $this->convert($bytes, true) : new StripResult($bytes, 'unknown', needsConversion: true),
            default => $convert ? $this->convert($bytes, false) : new StripResult($bytes, 'unknown'),
        };
    }

    /** `jpeg` | `png` | `webp` | `gif` | `heif` | `video` | `other`, from the first bytes only. */
    public function detect(string $b): string
    {
        return match (true) {
            str_starts_with($b, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($b, "\x89PNG\r\n\x1A\n") => 'png',
            strlen($b) >= 12 && str_starts_with($b, 'RIFF') && substr($b, 8, 4) === 'WEBP' => 'webp',
            str_starts_with($b, 'GIF87a') || str_starts_with($b, 'GIF89a') => 'gif',
            strlen($b) >= 12 && substr($b, 4, 4) === 'ftyp' => in_array(substr($b, 8, 4), self::IMAGE_BRANDS, true) ? 'heif' : 'video',
            str_starts_with($b, "\x1A\x45\xDF\xA3"), str_starts_with($b, 'OggS'), strlen($b) >= 12 && str_starts_with($b, 'RIFF') && substr($b, 8, 4) === 'AVI ', str_starts_with($b, "\x00\x00\x01\xBA") => 'video',
            default => 'other',
        };
    }

    // ---- JPEG --------------------------------------------------------------------------------------------------------------------

    private function jpeg(string $b): StripResult
    {
        $len = strlen($b);
        $out = "\xFF\xD8";
        $removed = [];
        $removedBytes = 0;
        $orientation = 1;
        $scans = 0;
        $trailer = 0;
        $i = 2;

        while (true) {
            if ($i + 1 >= $len) {
                return $this->bad($b, 'jpeg'); // no end of the picture
            }

            if ($b[$i] !== "\xFF") {
                return $this->bad($b, 'jpeg');
            }

            while ($i + 1 < $len && $b[$i + 1] === "\xFF") {
                $i++; // fill bytes
            }

            $marker = ord($b[$i + 1]);

            if ($marker === 0xD9) { // EOI: the primary picture ends HERE; whatever follows (MPF pictures, a Motion Photo video ...) is dropped
                if ($scans === 0) {
                    return $this->bad($b, 'jpeg'); // an end before any scan: nothing to keep a picture from
                }

                $out .= "\xFF\xD9";
                $trailer = $len - ($i + 2);

                break;
            }

            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD8)) { // markers without a length
                $out .= substr($b, $i, 2);
                $i += 2;

                continue;
            }

            if ($i + 3 >= $len) {
                return $this->bad($b, 'jpeg');
            }

            $size = (ord($b[$i + 2]) << 8) | ord($b[$i + 3]);

            if ($size < 2 || $i + 2 + $size > $len) {
                return $this->bad($b, 'jpeg');
            }

            $segment = substr($b, $i, 2 + $size);
            $isApp = $marker >= 0xE0 && $marker <= 0xEF;

            if ($marker === 0xDA) { // SOS: its header, then the entropy-coded data up to the next REAL marker (a progressive picture has several scans, with tables between them)
                $out .= $segment;
                $i += 2 + $size;
                $end = $this->scanEnd($b, $i);

                if ($end === null) {
                    return $this->bad($b, 'jpeg');
                }

                $out .= substr($b, $i, $end - $i);
                $i = $end;
                $scans++;

                continue;
            }

            // APP2 stays only as an ICC colour profile; the MPF segment (which points at the pictures appended after the end) and any other APP2 go
            $keepApp = $marker === 0xE2 ? substr($segment, 4, 12) === "ICC_PROFILE\0" : in_array($marker, self::KEEP_APP, true);

            if ($marker === 0xFE || ($isApp && ! $keepApp)) {
                $removed[] = match (true) {
                    $marker === 0xFE => 'COM',
                    $marker === 0xE2 => substr($segment, 4, 4) === "MPF\0" ? 'APP2-MPF' : 'APP2-other',
                    default => 'APP'.($marker - 0xE0),
                };
                $removedBytes += strlen($segment);

                if ($marker === 0xE1 && substr($segment, 4, 6) === "Exif\0\0") {
                    $orientation = $this->tiffOrientation(substr($segment, 10)) ?? $orientation;
                }
            } else {
                $out .= $segment;
            }

            $i += 2 + $size;
        }

        if ($orientation > 1) {
            return $this->turned($b, 'jpeg', $orientation);
        }

        if ($trailer > 0) {
            $removed[] = 'TRAILER';
            $removedBytes += $trailer;
        }

        return $removed === [] ? new StripResult($b, 'jpeg') : new StripResult($out, 'jpeg', $removed, $removedBytes, changed: true);
    }

    /**
     * Where the entropy-coded data that starts at `$at` ends: the offset of the next REAL marker. Inside the data `FF 00` is a stuffed byte, `FF D0` to `FF D7` are restart markers and a run of
     * `FF` is padding; none of them ends a scan. Null when the file ends first (no marker to walk on from).
     */
    private function scanEnd(string $b, int $at): ?int
    {
        $len = strlen($b);

        while (true) {
            $p = strpos($b, "\xFF", $at);

            if ($p === false || $p + 1 >= $len) {
                return null;
            }

            $next = ord($b[$p + 1]);

            if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7)) {
                $at = $p + 2;
            } elseif ($next === 0xFF) {
                $at = $p + 1;
            } else {
                return $p;
            }
        }
    }

    // ---- PNG ---------------------------------------------------------------------------------------------------------------------

    private function png(string $b): StripResult
    {
        $len = strlen($b);
        $out = substr($b, 0, 8);
        $removed = [];
        $removedBytes = 0;
        $i = 8;
        $ended = false;

        while ($i < $len) {
            if ($i + 12 > $len) {
                return $this->bad($b, 'png');
            }

            $size = unpack('N', substr($b, $i, 4))[1];
            $type = substr($b, $i + 4, 4);
            $total = 12 + $size;

            if ($size > 0x7FFFFFFF || $i + $total > $len || preg_match('/^[A-Za-z]{4}$/', $type) !== 1) {
                return $this->bad($b, 'png');
            }

            if (in_array($type, ['tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME'], true)) {
                $removed[] = $type;
                $removedBytes += $total;
            } else {
                $out .= substr($b, $i, $total);
            }

            $i += $total;

            if ($type === 'IEND') {
                $ended = true;

                if ($i < $len) { // whatever follows the end of the picture is dropped
                    $removed[] = 'TRAILER';
                    $removedBytes += $len - $i;
                }

                break;
            }
        }

        if (! $ended) {
            return $this->bad($b, 'png');
        }

        return $removed === [] ? new StripResult($b, 'png') : new StripResult($out, 'png', $removed, $removedBytes, changed: true);
    }

    // ---- WebP --------------------------------------------------------------------------------------------------------------------

    private function webp(string $b): StripResult
    {
        $len = strlen($b);
        $riff = unpack('V', substr($b, 4, 4))[1];

        if ($riff + 8 > $len || $riff < 4) {
            return $this->bad($b, 'webp');
        }

        $end = $riff + 8;
        $body = '';
        $removed = [];
        $removedBytes = 0;
        $orientation = 1;
        $i = 12;

        while ($i < $end) {
            if ($i + 8 > $end) {
                return $this->bad($b, 'webp');
            }

            $fourcc = substr($b, $i, 4);
            $size = unpack('V', substr($b, $i + 4, 4))[1];
            $total = 8 + $size + ($size & 1);

            if ($i + 8 + $size > $end) {
                return $this->bad($b, 'webp');
            }

            $total = min($total, $end - $i);
            $chunk = substr($b, $i, $total);

            if ($fourcc === 'EXIF' || $fourcc === 'XMP ') {
                $removed[] = $fourcc;
                $removedBytes += $total;

                if ($fourcc === 'EXIF') {
                    $data = substr($b, $i + 8, $size);
                    $orientation = $this->tiffOrientation(str_starts_with($data, "Exif\0\0") ? substr($data, 6) : $data) ?? $orientation;
                }
            } elseif ($fourcc === 'VP8X' && $size >= 10) {
                $flags = ord($chunk[8]);
                $chunk[8] = chr($flags & ~0x0C); // the EXIF (0x08) and XMP (0x04) flags
                $body .= $chunk;
            } else {
                $body .= $chunk;
            }

            $i += $total;
        }

        if ($end < $len) { // bytes beyond the RIFF size
            $removed[] = 'TRAILER';
            $removedBytes += $len - $end;
        }

        if ($removed === []) {
            return new StripResult($b, 'webp');
        }

        $out = 'RIFF'.pack('V', 4 + strlen($body)).'WEBP'.$body;

        // The picture is turned upright from the tag read above on the ALREADY STRIPPED bytes: the decoder reads EXIF from a WebP on some servers and not on others, and a decoder that
        // turned it by itself must not turn it twice.
        return $orientation > 1 ? $this->turnedWebp($out, $orientation) : new StripResult($out, 'webp', $removed, $removedBytes, changed: true);
    }

    // ---- GIF ---------------------------------------------------------------------------------------------------------------------

    private function gif(string $b): StripResult
    {
        $len = strlen($b);

        if ($len < 13) {
            return $this->bad($b, 'gif');
        }

        $flags = ord($b[10]);
        $i = 13 + (($flags & 0x80) !== 0 ? 3 * (1 << (($flags & 7) + 1)) : 0);

        if ($i > $len) {
            return $this->bad($b, 'gif');
        }

        $out = substr($b, 0, $i);
        $removed = [];
        $removedBytes = 0;

        while ($i < $len) {
            $introducer = ord($b[$i]);

            if ($introducer === 0x3B) { // the end of the file; bytes after it are dropped
                $out .= ';';

                if ($i + 1 < $len) {
                    $removed[] = 'TRAILER';
                    $removedBytes += $len - $i - 1;
                }

                $i = $len;

                break;
            }

            if ($introducer === 0x21) {
                if ($i + 2 > $len) {
                    return $this->bad($b, 'gif');
                }

                $label = ord($b[$i + 1]);
                $end = $this->gifSubBlocksEnd($b, $i + 2);

                if ($end === null) {
                    return $this->bad($b, 'gif');
                }

                $block = substr($b, $i, $end - $i);
                $drop = $label === 0xFE;

                if ($label === 0xFF) {
                    $id = substr($b, $i + 3, 11);
                    $drop = $id !== 'NETSCAPE2.0' && $id !== 'ANIMEXTS1.0';
                }

                if ($drop) {
                    $removed[] = $label === 0xFE ? 'comment' : 'application:'.trim(substr($b, $i + 3, 11));
                    $removedBytes += strlen($block);
                } else {
                    $out .= $block;
                }

                $i = $end;

                continue;
            }

            if ($introducer === 0x2C) { // image descriptor, its colour table, the LZW size byte, the data
                if ($i + 10 > $len) {
                    return $this->bad($b, 'gif');
                }

                $local = ord($b[$i + 9]);
                $start = $i + 10 + (($local & 0x80) !== 0 ? 3 * (1 << (($local & 7) + 1)) : 0) + 1;
                $end = $start <= $len ? $this->gifSubBlocksEnd($b, $start) : null;

                if ($end === null) {
                    return $this->bad($b, 'gif');
                }

                $out .= substr($b, $i, $end - $i);
                $i = $end;

                continue;
            }

            return $this->bad($b, 'gif');
        }

        return $removed === [] ? new StripResult($b, 'gif') : new StripResult($out, 'gif', $removed, $removedBytes, changed: true);
    }

    /** The offset just after a run of sub-blocks that starts at `$at` (size byte, data, ... a zero size), or null when it overruns the file. */
    private function gifSubBlocksEnd(string $b, int $at): ?int
    {
        $len = strlen($b);

        while (true) {
            if ($at >= $len) {
                return null;
            }

            $size = ord($b[$at]);
            $at += 1 + $size;

            if ($size === 0) {
                return $at <= $len ? $at : null;
            }

            if ($at > $len) {
                return null;
            }
        }
    }

    // ---- orientation, conversion -----------------------------------------------------------------------------------------------

    /** The EXIF Orientation (1-8) from a TIFF block (`II*\0` or `MM\0*`), or null: both byte orders, every offset checked. */
    public function tiffOrientation(string $tiff): ?int
    {
        $len = strlen($tiff);

        if ($len < 8) {
            return null;
        }

        $little = match (substr($tiff, 0, 4)) {
            "II*\0" => true,
            "MM\0*" => false,
            default => null,
        };

        if ($little === null) {
            return null;
        }

        $u16 = static fn (int $at): int => $little ? unpack('v', substr($tiff, $at, 2))[1] : unpack('n', substr($tiff, $at, 2))[1];
        $u32 = static fn (int $at): int => $little ? unpack('V', substr($tiff, $at, 4))[1] : unpack('N', substr($tiff, $at, 4))[1];
        $ifd = $u32(4);

        if ($ifd < 8 || $ifd + 2 > $len) {
            return null;
        }

        $count = $u16($ifd);

        for ($n = 0; $n < $count && $n < 200; $n++) {
            $entry = $ifd + 2 + 12 * $n;

            if ($entry + 12 > $len) {
                return null;
            }

            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);

                return $value >= 1 && $value <= 8 ? $value : null;
            }
        }

        return null;
    }

    /** A JPEG / WebP whose EXIF turns the picture: decoded, turned upright, encoded again in the same format, then stripped (the only lossy case of a lossless format). */
    private function turned(string $b, string $format, int $orientation): StripResult
    {
        try {
            $image = Image::fromBytes($b)->orient();
            $encoded = ($format === 'jpeg' ? $image->toJpeg() : $image->toWebp())->quality(self::QUALITY)->toBytes();
        } catch (Throwable) {
            throw ImageNotCleanableException::unsupportedFormat();
        }

        $clean = $format === 'jpeg' ? $this->jpeg($encoded) : $this->webp($encoded);

        return new StripResult($clean->bytes, $format, ['orientation'], 0, reencoded: true, changed: true, orientation: $orientation);
    }

    /** The WebP twin of turned(): the EXIF is gone from `$stripped` already, so the tag value is applied here, the way Intervention's own orient() does. */
    private function turnedWebp(string $stripped, int $orientation): StripResult
    {
        try {
            $image = Image::fromBytes($stripped);
            $image = match ($orientation) {
                2 => $image->flip(),
                3 => $image->rotate(180),
                4 => $image->rotate(180)->flip(),
                5 => $image->rotate(90)->flip(),
                6 => $image->rotate(90),
                7 => $image->rotate(270)->flip(),
                8 => $image->rotate(270),
                default => $image,
            };
            $encoded = $image->toWebp()->quality(self::QUALITY)->toBytes();
        } catch (Throwable) {
            throw ImageNotCleanableException::unsupportedFormat();
        }

        return new StripResult($this->webp($encoded)->bytes, 'webp', ['orientation'], 0, reencoded: true, changed: true, orientation: $orientation);
    }

    /** HEIC / AVIF and every other format this server can decode: JPEG, upright, quality 95. One it cannot decode is refused (or, for an unknown non-picture, passed through). */
    private function convert(string $b, bool $isPicture): StripResult
    {
        try {
            $encoded = Image::fromBytes($b)->orient()->toJpeg()->quality(self::QUALITY)->toBytes();
        } catch (Throwable) {
            if ($isPicture) {
                throw ImageNotCleanableException::unsupportedFormat();
            }

            return new StripResult($b, 'unknown'); // not a picture this server can read: not ours to judge (a video in a container we do not recognise)
        }

        $clean = $this->detect($encoded) === 'jpeg' ? $this->jpeg($encoded) : new StripResult($encoded, 'jpeg');

        return new StripResult($clean->bytes, 'converted', ['converted'], 0, converted: true, changed: true);
    }

    private function bad(string $b, string $format): StripResult
    {
        return new StripResult($b, $format, malformed: true);
    }
}
