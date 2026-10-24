<?php

namespace Tests\Feature\Media;

/**
 * ML-0 fixtures: small real pictures made with GD, then metadata injected BYTE BY BYTE (an EXIF block with a GPS IFD and a recognisable marker string, XMP, IPTC, a comment, an ICC profile, the
 * Adobe segment; PNG text / iTXt / eXIf / iCCP / tIME chunks; WebP VP8X + ICCP + EXIF + XMP; GIF comment, an XMP application extension and the NETSCAPE loop).
 */
trait BuildsImageFixtures
{
    public const MARKER = 'SECRET-GPS-MARKER-42';

    public const ICC = 'ICC-PROFILE-BYTES-0123456789-ICC-PROFILE-BYTES';

    /** A raw GD picture (40 x 30). */
    private function gd(): \GdImage
    {
        $image = imagecreatetruecolor(40, 30);
        imagefilledrectangle($image, 0, 0, 39, 29, imagecolorallocate($image, 200, 40, 40));
        imagefilledrectangle($image, 5, 5, 20, 20, imagecolorallocate($image, 20, 40, 220));

        return $image;
    }

    private function capture(callable $encode): string
    {
        ob_start();
        $encode();

        return (string) ob_get_clean();
    }

    // ---- EXIF ----------------------------------------------------------------------------------------------------------------------

    /** An EXIF TIFF block (little endian): ImageDescription = the marker, optional Orientation, a GPS IFD with the position. */
    private function tiff(?int $orientation = null, bool $bigEndian = false, string $marker = self::MARKER): string
    {
        $s = fn (int $v): string => $bigEndian ? pack('n', $v) : pack('v', $v);
        $l = fn (int $v): string => $bigEndian ? pack('N', $v) : pack('V', $v);
        $entries = [];
        $count = 2 + ($orientation !== null ? 1 : 0);
        $ifdSize = 2 + 12 * $count + 4;
        $descOffset = 8 + $ifdSize;
        $desc = $marker."\0";
        $gpsOffset = $descOffset + strlen($desc);
        $entries[] = $s(0x010E).$s(2).$l(strlen($desc)).$l($descOffset);

        if ($orientation !== null) {
            $entries[] = $s(0x0112).$s(3).$l(1).$s($orientation).$s(0);
        }

        $entries[] = $s(0x8825).$s(4).$l(1).$l($gpsOffset);

        $gps = $s(2).$s(0x0001).$s(2).$l(2)."N\0\0\0".$s(0x0002).$s(5).$l(3).$l($gpsOffset + 2 + 24 + 4).$l(0);
        $rationals = '';

        foreach ([[42, 1], [41, 1], [3000, 100]] as [$n, $d]) {
            $rationals .= $l($n).$l($d);
        }

        return ($bigEndian ? "MM\0*" : "II*\0").$l(8).$s($count).implode('', $entries).$l(0).$desc.$gps.$rationals;
    }

    private function exifApp1(?int $orientation = null, bool $bigEndian = false, string $marker = self::MARKER): string
    {
        return $this->segment(0xE1, "Exif\0\0".$this->tiff($orientation, $bigEndian, $marker));
    }

    private function segment(int $marker, string $payload): string
    {
        return "\xFF".chr($marker).pack('n', strlen($payload) + 2).$payload;
    }

    // ---- JPEG ----------------------------------------------------------------------------------------------------------------------

    /** @param  list<string>  $segments  complete segments, inserted after the JFIF segment @return string */
    private function jpeg(array $segments = []): string
    {
        $raw = $this->capture(fn () => imagejpeg($this->gd(), null, 85));
        // libgd writes its own COM segment ("CREATOR: gd-jpeg ..."): a fixture that stands for a CLEAN picture must not carry it
        $com = strpos($raw, hex2bin('fffe'));
        $com !== false && $raw = substr($raw, 0, $com).substr($raw, $com + 2 + ((ord($raw[$com + 2]) << 8) | ord($raw[$com + 3])));
        $apple = 2 + 2 + ((ord($raw[4]) << 8) | ord($raw[5])); // SOI + the APP0 JFIF segment

        return substr($raw, 0, $apple).implode('', $segments).substr($raw, $apple);
    }

    /** @return list<string> every kind of metadata a phone photo may carry, plus the three segments that must stay */
    private function dirtyJpegSegments(): array
    {
        return [
            $this->exifApp1(),
            $this->segment(0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta>".self::MARKER.'</x:xmpmeta>'),
            $this->segment(0xED, "Photoshop 3.0\0".'8BIM'.self::MARKER),
            $this->segment(0xFE, 'comment '.self::MARKER),
            $this->segment(0xE2, "ICC_PROFILE\0\x01\x01".self::ICC),
            $this->segment(0xEE, "Adobe\0\x64\x00\x00\x00\x00\x01"),
        ];
    }

    public const MARKER_SECOND = 'SECOND-PICTURE-GPS-MARKER-77';

    public const MARKER_VIDEO = 'MOTION-VIDEO-LOCATION-MARKER-99';

    /** A PRIMARY picture (EXIF with the marker, an ICC profile, an MPF segment), exactly as a phone writes it before the extras are appended. */
    private function primaryWithMpf(): string
    {
        return $this->jpeg([$this->exifApp1(), $this->segment(0xE2, "ICC_PROFILE\0\x01\x01".self::ICC), $this->segment(0xE2, "MPF\0II*\0".str_repeat("\x01", 40))]);
    }

    /** The primary + a SECOND complete JPEG (its own EXIF and GPS) + a fake MP4 box with a location atom, all appended after the first EOI. */
    private function phonePhoto(): string
    {
        $second = $this->jpeg([$this->exifApp1(null, false, self::MARKER_SECOND)]);
        $mp4 = "\x00\x00\x00\x20ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x30moov\xA9xyz+42.6977+023.3219/".self::MARKER_VIDEO;

        return $this->primaryWithMpf().$second.$mp4;
    }

    /** A progressive JPEG (several scans), without libgd's own comment. */
    private function progressiveJpeg(): string
    {
        $image = $this->gd();
        imageinterlace($image, true);
        $raw = $this->capture(fn () => imagejpeg($image, null, 85));
        $com = strpos($raw, hex2bin('fffe'));
        $com !== false && $raw = substr($raw, 0, $com).substr($raw, $com + 2 + ((ord($raw[$com + 2]) << 8) | ord($raw[$com + 3])));

        return $raw;
    }

    /** The bytes from the first SOS marker on. */
    private function fromSos(string $jpeg): string
    {
        $at = strpos($jpeg, "\xFF\xDA");
        $this->assertNotFalse($at);

        return substr($jpeg, $at);
    }

    /** @return list<int> the markers of the segments before SOS */
    private function jpegMarkers(string $jpeg): array
    {
        $out = [];
        $i = 2;

        while ($i + 3 < strlen($jpeg) && $jpeg[$i] === "\xFF") {
            $marker = ord($jpeg[$i + 1]);
            $out[] = $marker;

            if ($marker === 0xDA) {
                break;
            }

            $i += 2 + ((ord($jpeg[$i + 2]) << 8) | ord($jpeg[$i + 3]));
        }

        return $out;
    }

    // ---- PNG -----------------------------------------------------------------------------------------------------------------------

    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    /** @param  list<string>  $chunks  complete chunks, inserted after IHDR */
    private function png(array $chunks = []): string
    {
        $raw = $this->capture(fn () => imagepng($this->gd()));
        $afterIhdr = 8 + 12 + 13;

        return substr($raw, 0, $afterIhdr).implode('', $chunks).substr($raw, $afterIhdr);
    }

    /** @return list<string> */
    private function dirtyPngChunks(): array
    {
        return [
            $this->chunk('tEXt', "Comment\0".self::MARKER),
            $this->chunk('iTXt', "XML:com.adobe.xmp\0\0\0\0\0<x:xmpmeta>".self::MARKER.'</x:xmpmeta>'),
            $this->chunk('eXIf', $this->tiff()),
            $this->chunk('tIME', pack('nCCCCC', 2026, 10, 24, 9, 0, 0)),
            $this->chunk('zTXt', "Note\0\0".gzcompress(self::MARKER)),
            $this->chunk('iCCP', "icc\0\0".gzcompress(self::ICC)),
        ];
    }

    /** @return list<string> the chunk types in order */
    private function pngTypes(string $png): array
    {
        $types = [];
        $i = 8;

        while ($i + 8 <= strlen($png)) {
            $size = unpack('N', substr($png, $i, 4))[1];
            $types[] = substr($png, $i + 4, 4);
            $i += 12 + $size;
        }

        return $types;
    }

    private function pngData(string $png, string $type): string
    {
        $out = '';
        $i = 8;

        while ($i + 8 <= strlen($png)) {
            $size = unpack('N', substr($png, $i, 4))[1];
            substr($png, $i + 4, 4) === $type && $out .= substr($png, $i + 8, $size);
            $i += 12 + $size;
        }

        return $out;
    }

    // ---- WebP ----------------------------------------------------------------------------------------------------------------------

    private function riffChunk(string $fourcc, string $data): string
    {
        return $fourcc.pack('V', strlen($data)).$data.(strlen($data) & 1 ? "\0" : '');
    }

    /** A lossy WebP with VP8X + ICCP + the picture + EXIF + XMP (an odd-sized EXIF, so the padding byte is exercised). */
    private function webp(bool $dirty = true, ?int $orientation = null): string
    {
        $raw = $this->capture(fn () => imagewebp($this->gd(), null, 80));
        $image = substr($raw, 12); // the VP8 chunk as GD wrote it

        if (! $dirty) {
            return $raw;
        }

        $flags = 0x20 | 0x08 | 0x04;
        $vp8x = $this->riffChunk('VP8X', chr($flags)."\0\0\0".substr(pack('V', 39), 0, 3).substr(pack('V', 29), 0, 3));
        $exif = "Exif\0\0".$this->tiff($orientation).'x';
        $body = $vp8x.$this->riffChunk('ICCP', self::ICC).$image.$this->riffChunk('EXIF', $exif).$this->riffChunk('XMP ', '<x:xmpmeta>'.self::MARKER.'</x:xmpmeta>');

        return 'RIFF'.pack('V', 4 + strlen($body)).'WEBP'.$body;
    }

    /** @return array<string, string> fourcc => data of every chunk (the last one wins when repeated) */
    private function riffChunks(string $webp): array
    {
        $out = [];
        $i = 12;

        while ($i + 8 <= strlen($webp)) {
            $size = unpack('V', substr($webp, $i + 4, 4))[1];
            $out[substr($webp, $i, 4)] = substr($webp, $i + 8, $size);
            $i += 8 + $size + ($size & 1);
        }

        return $out;
    }

    // ---- GIF -----------------------------------------------------------------------------------------------------------------------

    private function gif(bool $dirty = true): string
    {
        $raw = $this->capture(fn () => imagegif($this->gd()));

        if (! $dirty) {
            return $raw;
        }

        $flags = ord($raw[10]);
        $at = 13 + (($flags & 0x80) !== 0 ? 3 * (1 << (($flags & 7) + 1)) : 0);
        $blocks = "\x21\xFE".chr(strlen('comment '.self::MARKER)).'comment '.self::MARKER."\x00"
            ."\x21\xFF\x0BXMP DataXMP".chr(strlen('<x:xmpmeta>'.self::MARKER)).'<x:xmpmeta>'.self::MARKER."\x00"
            ."\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";

        return substr($raw, 0, $at).$blocks.substr($raw, $at);
    }
}
