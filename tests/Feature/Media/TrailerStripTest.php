<?php

namespace Tests\Feature\Media;

use EasyCo\Media\Image\MetadataStripper;
use Illuminate\Support\Facades\Image;
use Tests\TestCase;

/**
 * ML-0.1: the picture ENDS at its end marker and nothing after it is kept. A modern phone appends other pictures (MPF: an HDR gain map, a depth map, each a complete JPEG with its own EXIF and
 * GPS) and a Motion Photo's video (with its own location atoms) after the first EOI: the cleaned original must hold none of it. PNG after IEND, WebP beyond the RIFF size and GIF after the trailer
 * byte follow the same rule.
 */
class TrailerStripTest extends TestCase
{
    use BuildsImageFixtures;

    private function strip(string $bytes): \EasyCo\Media\Image\StripResult
    {
        return (new MetadataStripper)->strip($bytes);
    }

    /** @return array{0: int, 1: int} */
    private function dims(string $bytes): array
    {
        $image = Image::fromBytes($bytes);

        return [$image->width(), $image->height()];
    }

    public function test_the_pictures_and_the_video_after_the_end_of_a_phone_photo_are_gone(): void
    {
        $primary = $this->primaryWithMpf();
        $phone = $this->phonePhoto();

        foreach ([self::MARKER, self::MARKER_SECOND, self::MARKER_VIDEO, 'MPF', 'ftyp', "\xA9xyz"] as $leak) {
            $this->assertStringContainsString($leak, $phone, "the fixture holds {$leak}");
        }

        $result = $this->strip($phone);

        $this->assertTrue($result->changed);

        foreach ([self::MARKER, self::MARKER_SECOND, self::MARKER_VIDEO, 'MPF', 'ftyp', "\xA9xyz", 'moov'] as $leak) {
            $this->assertStringNotContainsString($leak, $result->bytes, "{$leak} is gone");
        }

        $this->assertSame("\xFF\xD9", substr($result->bytes, -2), 'the file ends with the end marker of the primary picture');
        $this->assertSame([40, 30], $this->dims($result->bytes));
        $this->assertSame($this->fromSos($this->jpeg()), $this->fromSos($result->bytes), 'the primary picture\'s scan, from SOS to EOI, is identical');
        $this->assertContains('APP2-MPF', $result->removed);
        $this->assertContains('TRAILER', $result->removed);
        $this->assertGreaterThan(strlen($primary), strlen($phone));
        $this->assertGreaterThanOrEqual(strlen($phone) - strlen($primary), $result->removedBytes, "the trailer bytes are counted");
    }

    public function test_an_icc_profile_stays_while_the_mpf_segment_of_the_same_file_goes(): void
    {
        $result = $this->strip($this->primaryWithMpf());

        $this->assertStringContainsString(self::ICC, $result->bytes, 'the ICC colour profile stays');
        $this->assertStringNotContainsString('MPF', $result->bytes);
        $this->assertSame(1, count(array_keys($this->jpegMarkers($result->bytes), 0xE2, true)), 'one APP2 left: the profile');
        $this->assertSame(['APP1', 'APP2-MPF'], $result->removed);
    }

    public function test_any_other_app2_goes_too(): void
    {
        $result = $this->strip($this->jpeg([$this->segment(0xE2, "FPXR\0".self::MARKER)]));

        $this->assertSame(['APP2-other'], $result->removed);
        $this->assertStringNotContainsString(self::MARKER, $result->bytes);
    }

    public function test_a_file_with_nothing_but_a_trailer_is_a_changed_file(): void
    {
        $clean = $this->jpeg();
        $this->assertFalse($this->strip($clean)->changed);

        $result = $this->strip($clean."\x00\x00\x00\x08free....more");

        $this->assertTrue($result->changed);
        $this->assertSame(['TRAILER'], $result->removed);
        $this->assertSame($clean, $result->bytes, 'the clean picture comes back exactly');
        $this->assertSame(strlen("\x00\x00\x00\x08free....more"), $result->removedBytes);
        $this->assertFalse($this->strip($result->bytes)->changed, 'a second pass changes nothing');
    }

    public function test_a_progressive_jpeg_keeps_all_its_scans_and_loses_the_trailer(): void
    {
        $progressive = $this->progressiveJpeg();
        $this->assertGreaterThan(1, substr_count($progressive, "\xFF\xDA"), 'the fixture has several scans');
        $this->assertFalse($this->strip($progressive)->changed, 'a clean progressive picture is not touched');

        $result = $this->strip($progressive.$this->jpeg([$this->exifApp1(null, false, self::MARKER_SECOND)]).self::MARKER_VIDEO);

        $this->assertSame($progressive, $result->bytes, 'every scan is kept, the trailer is gone');
        $this->assertSame(['TRAILER'], $result->removed);
        $this->assertSame([40, 30], $this->dims($result->bytes));
    }

    public function test_restart_markers_and_stuffed_bytes_inside_a_scan_are_not_taken_for_the_end(): void
    {
        // a hand-made picture: the walker never decodes, it only has to find the real end. FF 00 is a stuffed byte (so `FF 00 D9` is NOT an end), FF D0 / FF D1 are restart markers, FF FF is padding.
        $head = "\xFF\xD8".$this->segment(0xDB, str_repeat("\x01", 65)).$this->segment(0xDD, "\x00\x04").$this->segment(0xDA, "\x01\x01\x00\x00\x3F\x00");
        $scan = "\x12\xFF\x00\xD9\x34\xFF\xD0\x56\xFF\xD1\x78\xFF\xFF\xFF\xD2\x9A\xFF\x00";
        $picture = $head.$scan."\xFF\xD9";
        $trailer = self::MARKER_SECOND."\xFF\xD8\xFF\xD9";

        $result = $this->strip($picture.$trailer);

        $this->assertSame($picture, $result->bytes, 'cut only at the real end');
        $this->assertSame(['TRAILER'], $result->removed);
        $this->assertSame(strlen($trailer), $result->removedBytes);
        $this->assertFalse($this->strip($picture)->changed);
    }

    public function test_a_jpeg_with_no_end_marker_is_malformed_and_returned_unchanged(): void
    {
        $jpeg = $this->jpeg();
        $cut = substr($jpeg, 0, -2);

        $result = $this->strip($cut);

        $this->assertTrue($result->malformed);
        $this->assertSame($cut, $result->bytes);
        $this->assertTrue($this->strip(substr($jpeg, 0, -40))->malformed, 'cut inside the scan');
    }

    public function test_bytes_after_the_end_of_a_png_a_webp_and_a_gif_are_cut(): void
    {
        $png = $this->png();
        $webp = $this->webp(false);
        $gif = $this->gif(false);

        foreach (['png' => $png, 'webp' => $webp, 'gif' => $gif] as $format => $clean) {
            $this->assertFalse($this->strip($clean)->changed, "{$format}: the clean fixture is untouched");

            $result = $this->strip($clean.'TRAILING-'.self::MARKER);

            $this->assertTrue($result->changed, $format);
            $this->assertSame(['TRAILER'], $result->removed, $format);
            $this->assertSame(strlen('TRAILING-'.self::MARKER), $result->removedBytes, $format);
            $this->assertStringNotContainsString(self::MARKER, $result->bytes, $format);
            $this->assertSame($clean, $result->bytes, "{$format}: the picture comes back exactly");
            $this->assertSame([40, 30], $this->dims($result->bytes), $format);
        }
    }

    public function test_a_webp_keeps_an_unknown_chunk_inside_the_riff_and_cuts_only_beyond_it(): void
    {
        $raw = $this->webp(false);
        $body = substr($raw, 12).$this->riffChunk('ZZZZ', 'unknown');
        $inside = 'RIFF'.pack('V', 4 + strlen($body)).'WEBP'.$body;

        $result = $this->strip($inside.'beyond');

        $this->assertSame($inside, $result->bytes, 'the unknown chunk is inside the RIFF size: it stays');
        $this->assertSame(['TRAILER'], $result->removed);
    }
}
