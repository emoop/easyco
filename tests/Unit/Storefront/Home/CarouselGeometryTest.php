<?php

namespace Tests\Unit\Storefront\Home;

use App\Storefront\Home\CarouselGeometry as G;
use App\Storefront\Home\TileGeometry;
use App\Storefront\Reader\ImageSetBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HP-5: the pure functions behind the «Карусел» component: the maximum step of the heights (the measured product card), the box of an item (width = height × ratio), the product card (ONE cell of the product
 * grid at 4 / 3 / the phone's columns with the grid's gap: the very formula of the grid) and the `sizes` of a picture in the box.
 */
class CarouselGeometryTest extends TestCase
{
    public function test_the_maximum_step_is_the_largest_step_not_above_the_measured_card_height(): void
    {
        $this->assertSame(537, G::CARD_HEIGHT, 'the card of the category grid at 4 columns / 1440px measured in the browser (HP-5 step 0)');
        $this->assertSame(480, G::maxHeight(), 'the largest step that is not above 537');
        $this->assertSame(480, G::maxHeight(480.0), 'the boundary is inclusive');
        $this->assertSame(420, G::maxHeight(479.9));
        $this->assertSame(360, G::maxHeight(400.0));
        $this->assertSame(80, G::maxHeight(10.0), 'never below the minimum');
        $this->assertSame(['80', '100', '120', '160', '200', '240', '300', '360', '420', '480'], G::heights());
        $this->assertContains(G::DEFAULT_HEIGHT, G::heights());
        $this->assertContains(G::DEFAULT_HEIGHT_PHONE, G::heights());
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function boxes(): array
    {
        $out = [];

        foreach ([80, 160, 480] as $height) {
            foreach (G::RATIOS as $ratio) {
                $out["{$height} {$ratio}"] = [$height, $ratio];
            }
        }

        return $out;
    }

    #[DataProvider('boxes')]
    public function test_the_box_width_is_the_height_times_the_ratio(int $height, string $ratio): void
    {
        [$a, $b] = array_map('intval', explode(':', $ratio));
        [$w, $h] = G::box($height, $ratio);

        $this->assertEqualsWithDelta($height * $a / $b, $w, 0.0001);
        $this->assertSame((float) $height, $h);

        $hint = G::hint($height, $ratio);
        $this->assertSame((int) (round($w / 10) * 10), $hint['rounded_width']);
        $this->assertSame((int) (round($w * 2 / 10) * 10), $hint['double_width']);
        $this->assertSame((int) (round($h * 2 / 10) * 10), $hint['double_height']);
    }

    public function test_known_boxes_and_an_unknown_ratio_is_the_default(): void
    {
        $this->assertSame([240.0, 160.0], G::box(160, '3:2'));
        $this->assertSame([160.0, 160.0], G::box(160, '1:1'));
        $this->assertSame([480.0, 160.0], G::box(160, '3:1'));
        $this->assertSame(G::box(160, '3:2'), G::box(160, '7:7'));
        $this->assertSame(240, G::hint(160, '3:2')['rounded_width']);
        $this->assertSame(480, G::hint(160, '3:2')['double_width']);
    }

    /** @return array<string, array{0: int, 1: int}> */
    public static function cards(): array
    {
        $out = [];

        foreach ([[4, 'desktop'], [3, 'tablet'], [2, 'phone 2'], [1, 'phone 1']] as [$columns, $name]) {
            foreach ([0, 16, 32] as $gap) {
                $out["{$name} gap {$gap}"] = [$columns, $gap];
            }
        }

        return $out;
    }

    #[DataProvider('cards')]
    public function test_a_product_card_is_exactly_one_cell_of_the_grid(int $columns, int $gap): void
    {
        $card = G::card($columns, $gap);

        // the grid: N equal columns and a gap between them (the very formula of the tiles / the product grid)
        $this->assertEqualsWithDelta(G::REFERENCE_WIDTH, $card['width'] * $columns + ($columns - 1) * $gap, 0.001);
        $this->assertEqualsWithDelta(TileGeometry::tile($columns, $gap, 'square')['width'], $card['width'], 0.0001, 'the same cell width as the tiles / grid of equal columns');
        $this->assertEqualsWithDelta($card['width'] * 444 / 370, $card['image_height'], 0.0001);
    }

    public function test_the_card_numbers_of_the_default_desktop_and_of_a_phone(): void
    {
        $this->assertEqualsWithDelta((1320 - 48) / 4, G::card(4, 16)['width'], 0.001);
        $this->assertSame(320, G::card(4, 16)['rounded_width']);
        $this->assertSame(640, G::card(4, 16)['double_width']);
        $this->assertEqualsWithDelta((358 - 8) / 2, G::card(2, 8, G::PHONE_REFERENCE_WIDTH)['width'], 0.001);
        $this->assertSame(4, G::CARD_COLUMNS);
        $this->assertSame(3, G::CARD_COLUMNS_TABLET);
    }

    public function test_the_product_card_images_use_the_catalogs_own_sizes_for_4_3_and_the_phone_columns(): void
    {
        $sizes = ImageSetBuilder::cardSizes(G::CARD_COLUMNS, 2, 16);

        $this->assertStringContainsString('(min-width: 64rem) calc((100vw - 4rem) / 4)', $sizes);
        $this->assertStringContainsString('(min-width: 48rem) calc((100vw - 3rem) / 3)', $sizes);
    }

    public function test_a_picture_says_the_width_it_is_displayed_at_shown_whole_and_never_enlarged(): void
    {
        // desktop box 180 × 120 (height 120, 3:2); the phone box 150 × 100 (height 100)
        $this->assertSame('(min-width: 48rem) 60px, 60px', G::pictureSizes(120, 100, '3:2', 60, 30), 'a tiny logo keeps its own width');
        $this->assertSame('(min-width: 48rem) 180px, 150px', G::pictureSizes(120, 100, '3:2', 1600, 900), 'a big one is shrunk to the box');
        $this->assertSame('(min-width: 48rem) 180px, 150px', G::pictureSizes(120, 100, '3:2', 600, 150), 'a wide one is as wide as the box');
        $this->assertSame('(min-width: 48rem) 30px, 25px', G::pictureSizes(120, 100, '3:2', 150, 600), 'a tall one is as wide as its height allows');
    }

    public function test_the_sizes_of_a_picture_in_the_box(): void
    {
        $this->assertSame('(min-width: 48rem) 240px, 180px', G::sizes(160, 120, '3:2'));
        $this->assertSame('(min-width: 48rem) 160px, 120px', G::sizes(160, 120, '1:1'));
        $this->assertSame('(min-width: 48rem) 285px, 160px', G::sizes(160, 90, '16:9'), 'rounded UP: never smaller than the box');
    }
}
