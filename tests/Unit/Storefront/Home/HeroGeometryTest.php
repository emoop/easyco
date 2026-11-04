<?php

namespace Tests\Unit\Storefront\Home;

use App\Storefront\Home\HeroGeometry as G;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HP-6: the pure functions behind the «Hero слайдер» component: the closed lists, the box of the hero on a desktop (1320px reference, the desktop ratios) and on a phone (358px reference, the phone ratios), the size to
 * aim at (rounded to the nearest 10) and the 2× size.
 */
class HeroGeometryTest extends TestCase
{
    public function test_the_closed_lists_and_their_defaults(): void
    {
        $this->assertSame(6, G::SLIDES_MAX);
        $this->assertSame(['3', '4', '5', '6', '8', '10'], G::SECONDS);
        $this->assertSame(['3:1', '21:9', '2:1', '16:9'], G::RATIOS);
        $this->assertSame(['1:1', '4:5', '3:4', '4:3'], G::PHONE_RATIOS);
        $this->assertSame(['5', '21:9', '4:5'], [G::DEFAULT_SECONDS, G::DEFAULT_RATIO, G::DEFAULT_PHONE_RATIO]);
        $this->assertContains(G::DEFAULT_SECONDS, G::SECONDS);
        $this->assertContains(G::DEFAULT_RATIO, G::RATIOS);
        $this->assertContains(G::DEFAULT_PHONE_RATIO, G::PHONE_RATIOS);
        $this->assertSame([1320, 358], [G::REFERENCE_WIDTH, G::PHONE_REFERENCE_WIDTH]);
    }

    /** @return array<string, array{0: string, 1: int, 2: int, 3: int, 4: int}> ratio, rounded width, rounded height, 2× width, 2× height */
    public static function desktops(): array
    {
        return [
            '3:1' => ['3:1', 1320, 440, 2640, 880],
            '21:9' => ['21:9', 1320, 570, 2640, 1130],
            '2:1' => ['2:1', 1320, 660, 2640, 1320],
            '16:9' => ['16:9', 1320, 740, 2640, 1490],
        ];
    }

    #[DataProvider('desktops')]
    public function test_the_desktop_picture_is_the_1320px_reference_and_the_ratio(string $ratio, int $w, int $h, int $w2, int $h2): void
    {
        $hint = G::desktop($ratio);

        $this->assertSame([$w, $h, $w2, $h2], [$hint['rounded_width'], $hint['rounded_height'], $hint['double_width'], $hint['double_height']]);
        $this->assertSame(1320.0, $hint['width']);
        [$rw, $rh] = array_map('intval', explode(':', $ratio));
        $this->assertEqualsWithDelta(1320 * $rh / $rw, $hint['height'], 0.0001, 'the height is the width over the ratio');
    }

    /** @return array<string, array{0: string, 1: int, 2: int, 3: int, 4: int}> */
    public static function phones(): array
    {
        return [
            '1:1' => ['1:1', 360, 360, 720, 720],
            '4:5' => ['4:5', 360, 450, 720, 900],
            '3:4' => ['3:4', 360, 480, 720, 950],
            '4:3' => ['4:3', 360, 270, 720, 540],
        ];
    }

    #[DataProvider('phones')]
    public function test_the_phone_picture_is_the_358px_reference_and_the_phone_ratio(string $ratio, int $w, int $h, int $w2, int $h2): void
    {
        $hint = G::phone($ratio);

        $this->assertSame([$w, $h, $w2, $h2], [$hint['rounded_width'], $hint['rounded_height'], $hint['double_width'], $hint['double_height']]);
        $this->assertSame(358.0, $hint['width']);
        [$rw, $rh] = array_map('intval', explode(':', $ratio));
        $this->assertEqualsWithDelta(358 * $rh / $rw, $hint['height'], 0.0001);
    }

    public function test_the_phone_box_is_taller_than_wide_for_the_portrait_ratios_and_the_desktop_box_is_always_wider_than_tall(): void
    {
        $this->assertGreaterThan(G::phone('4:5')['width'], G::phone('4:5')['height']);
        $this->assertGreaterThan(G::phone('3:4')['width'], G::phone('3:4')['height']);
        $this->assertSame(G::phone('1:1')['width'], G::phone('1:1')['height']);

        foreach (G::RATIOS as $ratio) {
            $this->assertGreaterThan(G::desktop($ratio)['height'], G::desktop($ratio)['width'], $ratio);
        }
    }

    public function test_a_ratio_outside_its_list_is_the_default_of_that_side(): void
    {
        $this->assertSame(G::desktop('21:9'), G::desktop('7:3'));
        $this->assertSame(G::desktop('21:9'), G::desktop('4:5'), 'a phone ratio is not a desktop one');
        $this->assertSame(G::phone('4:5'), G::phone('21:9'), 'a desktop ratio is not a phone one');
        $this->assertSame(G::phone('4:5'), G::phone(''));
        $this->assertSame([1320.0, 1320 * 9 / 21], G::box('nonsense'), 'the box of an unknown ratio is the default desktop one');
    }

    public function test_the_tens_rounding_and_the_class_slug(): void
    {
        $this->assertSame([570, 450, 0, 10], [G::tens(565.7), G::tens(447.5), G::tens(4.9), G::tens(5.0)]);
        $this->assertSame('21-9', G::slug('21:9'));
        $this->assertSame('4-5', G::slug('4:5'));
    }
}
