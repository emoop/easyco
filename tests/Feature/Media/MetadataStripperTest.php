<?php

namespace Tests\Feature\Media;

use EasyCo\Media\Exceptions\ImageNotCleanableException;
use EasyCo\Media\Image\MetadataStripper;
use Illuminate\Support\Facades\Image;
use Tests\TestCase;

/**
 * ML-0: MetadataStripper removes EXIF / GPS / XMP / IPTC / comments from an image original losslessly. Fixtures are real GD pictures with metadata injected byte by byte
 * (BuildsImageFixtures); "lossless" is proved by comparing the picture's own bytes before and after, never only by decoding.
 */
class MetadataStripperTest extends TestCase
{
    use BuildsImageFixtures;

    private function strip(string $bytes): \EasyCo\Media\Image\StripResult
    {
        return (new MetadataStripper)->strip($bytes);
    }

    /** @return array{0: int, 1: int} the decoded size */
    private function dims(string $bytes): array
    {
        $image = Image::fromBytes($bytes);

        return [$image->width(), $image->height()];
    }

    // ---- JPEG ----------------------------------------------------------------------------------------------------------------------

    public function test_a_jpeg_loses_exif_gps_xmp_iptc_and_comment_and_keeps_jfif_icc_and_adobe(): void
    {
        $dirty = $this->jpeg($this->dirtyJpegSegments());
        $this->assertStringContainsString(self::MARKER, $dirty);

        $result = $this->strip($dirty);

        $this->assertTrue($result->changed);
        $this->assertSame('jpeg', $result->format);
        $this->assertStringNotContainsString(self::MARKER, $result->bytes, 'the marker is in EXIF, XMP, IPTC and the comment: all gone');
        $this->assertStringNotContainsString('Exif', $result->bytes);
        $this->assertStringNotContainsString('8BIM', $result->bytes);
        $this->assertSame(['APP1', 'APP1', 'APP13', 'COM'], $result->removed);
        $this->assertGreaterThan(200, $result->removedBytes);

        $markers = $this->jpegMarkers($result->bytes);
        $this->assertContains(0xE0, $markers, 'JFIF stays');
        $this->assertContains(0xE2, $markers, 'the ICC profile stays');
        $this->assertContains(0xEE, $markers, 'Adobe stays');
        $this->assertNotContains(0xE1, $markers);
        $this->assertNotContains(0xED, $markers);
        $this->assertNotContains(0xFE, $markers);
        $this->assertStringContainsString(self::ICC, $result->bytes);
        $this->assertSame([40, 30], $this->dims($result->bytes));
    }

    public function test_a_jpeg_strip_is_lossless_the_scan_bytes_are_identical(): void
    {
        $dirty = $this->jpeg($this->dirtyJpegSegments());
        $result = $this->strip($dirty);

        $this->assertSame($this->fromSos($dirty), $this->fromSos($result->bytes), 'from SOS to the end, byte for byte');
        $this->assertFalse($result->reencoded);
        $this->assertLessThan(strlen($dirty), strlen($result->bytes));
    }

    public function test_a_clean_jpeg_is_returned_as_it_is_and_a_second_pass_changes_nothing(): void
    {
        $clean = $this->jpeg();
        $first = $this->strip($clean);

        $this->assertFalse($first->changed);
        $this->assertSame($clean, $first->bytes);

        $dirty = $this->strip($this->jpeg($this->dirtyJpegSegments()));
        $second = $this->strip($dirty->bytes);
        $this->assertFalse($second->changed);
        $this->assertSame($dirty->bytes, $second->bytes);
    }

    public function test_other_app_segments_are_dropped_and_non_app_markers_stay(): void
    {
        $result = $this->strip($this->jpeg([$this->segment(0xE3, 'Meta'.self::MARKER), $this->segment(0xEC, 'Ducky'.self::MARKER)]));

        $this->assertSame(['APP3', 'APP12'], $result->removed);
        $this->assertStringNotContainsString(self::MARKER, $result->bytes);
        $this->assertContains(0xDB, $this->jpegMarkers($result->bytes), 'the quantisation tables stay');
        $this->assertContains(0xC0, array_merge($this->jpegMarkers($result->bytes), [0xC0, 0xC2]));
    }

    // ---- orientation -------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: bool}> */
    public static function byteOrders(): array
    {
        return ['little endian' => [false], 'big endian' => [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('byteOrders')]
    public function test_a_turned_jpeg_is_made_upright_and_stripped_and_it_is_the_only_re_encode(bool $bigEndian): void
    {
        $turned = $this->jpeg([$this->exifApp1(6, $bigEndian)]);
        $this->assertSame(6, (new MetadataStripper)->tiffOrientation($this->tiff(6, $bigEndian)));

        $result = $this->strip($turned);

        $this->assertTrue($result->reencoded);
        $this->assertTrue($result->changed);
        $this->assertStringNotContainsString(self::MARKER, $result->bytes);
        $this->assertStringNotContainsString('Exif', $result->bytes);
        $this->assertSame([30, 40], $this->dims($result->bytes), 'the raw pixels are 40 x 30: upright they are 30 x 40');

        $this->assertFalse($this->strip($this->jpeg($this->dirtyJpegSegments()))->reencoded, 'no orientation tag: never re-encoded');
        $this->assertFalse($this->strip($this->jpeg([$this->exifApp1(1)]))->reencoded, 'orientation 1 is upright already');
    }

    public function test_the_orientation_reader_is_safe_with_garbage(): void
    {
        $stripper = new MetadataStripper;

        foreach (['', 'II*', "II*\0\xFF\xFF\xFF\xFF", "MM\0*\0\0\0\x08\xFF\xFF", 'not a tiff block at all', "II*\0\x08\0\0\0\x01\0\x12\x01\x03\0\x01\0\0\0\x09\0\0\0"] as $tiff) {
            $this->assertNull($stripper->tiffOrientation($tiff), json_encode($tiff));
        }
    }

    // ---- PNG -----------------------------------------------------------------------------------------------------------------------

    public function test_a_png_loses_its_text_exif_and_time_chunks_and_keeps_the_profile_and_the_pixels(): void
    {
        $dirty = $this->png($this->dirtyPngChunks());
        $this->assertStringContainsString(self::MARKER, $dirty);

        $result = $this->strip($dirty);

        $this->assertTrue($result->changed);
        $this->assertSame(['tEXt', 'iTXt', 'eXIf', 'tIME', 'zTXt'], $result->removed);
        $this->assertStringNotContainsString(self::MARKER, $result->bytes);
        $types = $this->pngTypes($result->bytes);

        foreach (['tEXt', 'iTXt', 'eXIf', 'tIME', 'zTXt'] as $gone) {
            $this->assertNotContains($gone, $types);
        }

        $this->assertContains('iCCP', $types, 'the colour profile stays');
        $this->assertSame(self::ICC, gzuncompress($this->pngData($result->bytes, 'iCCP') === '' ? '' : substr($this->pngData($result->bytes, 'iCCP'), 5)));
        $this->assertSame($this->pngData($dirty, 'IDAT'), $this->pngData($result->bytes, 'IDAT'), 'the pixel data is identical');
        $this->assertSame('IEND', end($types));
        $this->assertSame([40, 30], $this->dims($result->bytes));
    }

    // ---- WebP ----------------------------------------------------------------------------------------------------------------------

    public function test_a_webp_loses_exif_and_xmp_clears_the_flags_and_keeps_icc_and_the_picture(): void
    {
        $dirty = $this->webp();
        $this->assertStringContainsString(self::MARKER, $dirty);

        $result = $this->strip($dirty);

        $this->assertTrue($result->changed);
        $this->assertSame(['EXIF', 'XMP '], $result->removed);
        $this->assertStringNotContainsString(self::MARKER, $result->bytes);
        $before = $this->riffChunks($dirty);
        $after = $this->riffChunks($result->bytes);

        $this->assertArrayNotHasKey('EXIF', $after);
        $this->assertArrayNotHasKey('XMP ', $after);
        $this->assertSame($before['ICCP'], $after['ICCP'], 'the colour profile stays');
        $this->assertSame($before['VP8 '], $after['VP8 '], 'the picture chunk is identical');
        $this->assertSame(0x20, ord($after['VP8X'][0]), 'only the ICC flag is left: EXIF (0x08) and XMP (0x04) cleared');
        $this->assertSame(strlen($result->bytes) - 8, unpack('V', substr($result->bytes, 4, 4))[1], 'the RIFF size is right');
        $this->assertSame([40, 30], $this->dims($result->bytes));
        $this->assertFalse($result->reencoded);
    }

    public function test_a_turned_webp_is_made_upright_and_stripped(): void
    {
        $result = $this->strip($this->webp(true, 8));

        $this->assertTrue($result->reencoded);
        $this->assertStringNotContainsString(self::MARKER, $result->bytes);
        $this->assertSame([30, 40], $this->dims($result->bytes));
    }

    // ---- GIF -----------------------------------------------------------------------------------------------------------------------

    public function test_a_gif_loses_its_comment_and_xmp_and_keeps_the_netscape_loop(): void
    {
        $dirty = $this->gif();
        $this->assertStringContainsString(self::MARKER, $dirty);

        $result = $this->strip($dirty);

        $this->assertTrue($result->changed);
        $this->assertSame(['comment', 'application:XMP DataXMP'], $result->removed);
        $this->assertStringNotContainsString(self::MARKER, $result->bytes);
        $this->assertStringContainsString("NETSCAPE2.0\x03\x01\x00\x00\x00", $result->bytes);
        $this->assertSame([40, 30], $this->dims($result->bytes));
        $this->assertFalse($this->strip($result->bytes)->changed);
    }

    // ---- malformed ---------------------------------------------------------------------------------------------------------------

    public function test_a_broken_structure_is_returned_unchanged_and_flagged(): void
    {
        $jpeg = $this->jpeg($this->dirtyJpegSegments());
        $png = $this->png($this->dirtyPngChunks());
        $webp = $this->webp();
        $gif = $this->gif();
        $cases = [
            'a jpeg cut inside a segment' => substr($jpeg, 0, 60),
            'a jpeg with a length that overruns' => substr($jpeg, 0, 2)."\xFF\xE1\xFF\xFF".substr($jpeg, 6),
            'a jpeg with no scan' => "\xFF\xD8\xFF\xE0\x00\x04ab\xFF\xD9",
            'a png cut inside a chunk' => substr($png, 0, 40),
            'a png with no end' => substr($png, 0, strlen($png) - 12),
            'a png with a bad chunk length' => substr($png, 0, 33)."\x7F\xFF\xFF\xFF".substr($png, 37),
            'a webp cut short' => substr($webp, 0, 50),
            'a webp whose size lies' => substr($webp, 0, 4).pack('V', 999999).substr($webp, 8),
            'a gif cut inside a block' => substr($gif, 0, 40),
        ];

        foreach ($cases as $name => $bytes) {
            $result = $this->strip($bytes);

            $this->assertTrue($result->malformed, $name);
            $this->assertFalse($result->changed, $name);
            $this->assertSame($bytes, $result->bytes, "{$name}: the input comes back, never a broken file");
        }
    }

    // ---- other formats -------------------------------------------------------------------------------------------------------------

    public function test_a_format_that_cannot_be_decoded_is_refused_and_video_and_junk_pass_through(): void
    {
        $heic = "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic".str_repeat("\x07", 64);

        try {
            $this->strip($heic);
            $this->fail('a picture that cannot be cleaned was accepted');
        } catch (ImageNotCleanableException $e) {
            $this->assertStringContainsString('ImageMagick', $e->getMessage());
        }

        $mp4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x01", 64).self::MARKER;
        $result = $this->strip($mp4);
        $this->assertSame($mp4, $result->bytes, 'a video is not touched');
        $this->assertSame('video', $result->format);
        $this->assertFalse($result->changed);

        foreach (["\x1A\x45\xDF\xA3".str_repeat('x', 40), 'just some text'] as $other) {
            $this->assertSame($other, $this->strip($other)->bytes);
        }
    }

    public function test_a_picture_the_server_can_decode_in_another_format_becomes_a_jpeg_without_metadata(): void
    {
        // a BMP is not one of the four lossless formats and GD decodes it
        $bmp = $this->capture(fn () => imagebmp($this->gd()));
        $result = $this->strip($bmp);

        $this->assertTrue($result->converted);
        $this->assertSame('converted', $result->format);
        $this->assertSame('jpg', $result->extension());
        $this->assertStringStartsWith("\xFF\xD8\xFF", $result->bytes);
        $this->assertSame([40, 30], $this->dims($result->bytes));
    }

    public function test_without_conversion_the_formats_that_cannot_be_cleaned_losslessly_are_flagged_not_changed(): void
    {
        $heic = "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic".str_repeat("\x07", 64);
        $result = (new MetadataStripper)->strip($heic, convert: false);

        $this->assertTrue($result->needsConversion);
        $this->assertSame($heic, $result->bytes);
        $this->assertFalse($result->changed);
    }

    public function test_the_format_is_found_by_the_content_not_by_a_name(): void
    {
        $stripper = new MetadataStripper;

        $this->assertSame('jpeg', $stripper->detect($this->jpeg()));
        $this->assertSame('png', $stripper->detect($this->png()));
        $this->assertSame('webp', $stripper->detect($this->webp()));
        $this->assertSame('gif', $stripper->detect($this->gif()));
        $this->assertSame('heif', $stripper->detect("\x00\x00\x00\x18ftypavif".str_repeat("\0", 20)));
        $this->assertSame('video', $stripper->detect("\x00\x00\x00\x18ftypqt  ".str_repeat("\0", 20)));
        $this->assertSame('other', $stripper->detect('plain'));
    }
}
