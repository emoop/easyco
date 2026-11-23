<?php

namespace Tests\Feature\Logo;

use App\Settings\LogoGenerator;
use App\Settings\SiteLogo;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\MakesLogoFixtures;

/**
 * LOGO-1 (site-logo.md): the derivatives, made with GD: exact sizes, transparency kept, never upscaled, and a hand-built ICO that parses (header, entry count and sizes, each entry a PNG).
 */
class LogoGeneratorTest extends TestCase
{
    use MakesLogoFixtures;

    private const PNG = "\x89PNG\r\n\x1a\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(extension_loaded('gd'), 'GD is the default driver and must be present');
    }

    private function pngInfo(string $png): array
    {
        $size = getimagesizefromstring($png);

        return [$size[0], $size[1], $size[2]];
    }

    /** ★ PNG 32 and 180 have exactly those sizes and are PNGs */
    public function test_the_icon_png_sizes_are_exact(): void
    {
        $icon = (new LogoGenerator)->icon($this->pngBytes(640, 640));

        $this->assertSame([32, 32, IMAGETYPE_PNG], $this->pngInfo($icon['png32']));
        $this->assertSame([180, 180, IMAGETYPE_PNG], $this->pngInfo($icon['png180']));
        $this->assertStringStartsWith(self::PNG, $icon['png32']);
        $this->assertStringStartsWith(self::PNG, $icon['png180']);
    }

    /** ★ the transparent corner stays transparent: alpha is kept */
    public function test_alpha_is_preserved(): void
    {
        $icon = (new LogoGenerator)->icon($this->pngBytes(512, 512));
        $image = imagecreatefromstring($icon['png180']);

        $corner = imagecolorat($image, 2, 2);
        $this->assertSame(127, ($corner >> 24) & 0x7F, 'the corner is fully transparent');
        $centre = imagecolorat($image, 90, 90);
        $this->assertSame(0, ($centre >> 24) & 0x7F, 'the block is opaque');

        $wide = (new LogoGenerator)->wide($this->pngBytes(800, 200));
        $corner = imagecolorat(imagecreatefromstring($wide['bytes']), 1, 1);
        $this->assertSame(127, ($corner >> 24) & 0x7F, 'the logo keeps its transparency too');
    }

    /** ★ a wide logo is scaled DOWN to 400 px and never upscaled */
    public function test_the_logo_is_at_most_400_px_wide_and_never_upscaled(): void
    {
        $generator = new LogoGenerator;

        $big = $generator->wide($this->pngBytes(1600, 400));
        $this->assertSame([400, 100], [$big['w'], $big['h']]);
        $this->assertSame([400, 100, IMAGETYPE_PNG], $this->pngInfo($big['bytes']));

        $small = $generator->wide($this->pngBytes(250, 60));
        $this->assertSame([250, 60], [$small['w'], $small['h']], 'a 250 px logo stays 250 px');
        $this->assertSame([250, 60, IMAGETYPE_PNG], $this->pngInfo($small['bytes']));

        $exact = $generator->wide($this->pngBytes(400, 90));
        $this->assertSame([400, 90], [$exact['w'], $exact['h']]);
        $this->assertSame(SiteLogo::LOGO_MAX_WIDTH, $exact['w']);
    }

    /** a WebP source works the same */
    public function test_a_webp_source_is_read(): void
    {
        $this->assertTrue((imagetypes() & IMG_WEBP) !== 0, 'GD has WebP here');
        $wide = (new LogoGenerator)->wide($this->webpBytes(900, 300));

        $this->assertSame([400, 133, IMAGETYPE_PNG], $this->pngInfo($wide['bytes']));
        $this->assertSame([32, 32, IMAGETYPE_PNG], $this->pngInfo((new LogoGenerator)->icon($this->webpBytes(600, 600))['png32']));
    }

    /** ★ the ICO parses: header, entry count, sizes, and every entry starts with the PNG signature */
    public function test_the_ico_is_a_container_of_png_entries(): void
    {
        $ico = (new LogoGenerator)->icon($this->pngBytes(512, 512))['ico'];

        $header = unpack('vreserved/vtype/vcount', substr($ico, 0, 6));
        $this->assertSame(['reserved' => 0, 'type' => 1, 'count' => 3], $header);

        $sizes = [];
        $end = 0;

        for ($i = 0; $i < 3; $i++) {
            $entry = unpack('Cwidth/Cheight/Ccolours/Creserved/vplanes/vbits/Vbytes/Voffset', substr($ico, 6 + 16 * $i, 16));
            $this->assertSame(0, $entry['reserved']);
            $this->assertSame(1, $entry['planes']);
            $this->assertSame(32, $entry['bits']);
            $this->assertSame($entry['width'], $entry['height']);

            $png = substr($ico, $entry['offset'], $entry['bytes']);
            $this->assertSame($entry['bytes'], strlen($png), 'the entry lies inside the file');
            $this->assertStringStartsWith(self::PNG, $png);
            $this->assertSame([$entry['width'], $entry['width'], IMAGETYPE_PNG], $this->pngInfo($png), 'the entry is a PNG of the size it announces');

            $sizes[] = $entry['width'];
            $end = max($end, $entry['offset'] + $entry['bytes']);
        }

        $this->assertSame([16, 32, 48], $sizes);
        $this->assertSame(strlen($ico), $end, 'nothing is left over');
    }

    public function test_the_ico_builder_keeps_the_offsets_right_for_any_entries(): void
    {
        $a = str_repeat('A', 10);
        $b = str_repeat('B', 25);
        $ico = LogoGenerator::ico([16 => $a, 32 => $b]);

        $this->assertSame(6 + 32 + 35, strlen($ico));
        $first = unpack('Voffset', substr($ico, 6 + 12, 4));
        $second = unpack('Voffset', substr($ico, 6 + 16 + 12, 4));
        $this->assertSame(38, $first['offset']);
        $this->assertSame(48, $second['offset']);
        $this->assertSame($a, substr($ico, 38, 10));
        $this->assertSame($b, substr($ico, 48, 25));
    }

    public function test_a_source_that_is_not_a_picture_is_a_plain_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        (new LogoGenerator)->wide('this is not an image');
    }
}
