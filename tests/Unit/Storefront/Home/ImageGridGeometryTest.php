<?php

namespace Tests\Unit\Storefront\Home;

use App\Storefront\Home\ImageGridGeometry as G;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HP-2: the one pure function behind the admin's size diagram and the tests. For every scheme, every proportion and a gap of 0 and of 32: the cells never exceed the box, they add up to it, and the
 * even schemes have equal cells; `uneven-a` has the left cells at half the box height minus the gap, the middle cell the full height and the right cells a third each (gap handled).
 */
class ImageGridGeometryTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: int}> */
    public static function grids(): array
    {
        $out = [];

        foreach (array_keys(G::SCHEMES) as $scheme) {
            foreach (G::RATIOS as $ratio) {
                foreach ([0, 32] as $gap) {
                    $out["{$scheme} {$ratio} gap {$gap}"] = [$scheme, $ratio, $gap];
                }
            }
        }

        return $out;
    }

    #[DataProvider('grids')]
    public function test_the_cells_fill_the_box_and_never_exceed_it(string $scheme, string $ratio, int $gap): void
    {
        [$width, $height] = G::box($ratio);
        $cells = G::cells($scheme, $ratio, $gap);

        $this->assertSame(G::names($scheme), array_column($cells, 'name'));

        foreach ($cells as $cell) {
            $this->assertGreaterThan(0, $cell['width']);
            $this->assertLessThanOrEqual($width + 0.001, $cell['width']);
            $this->assertLessThanOrEqual($height + 0.001, $cell['height']);
        }

        // the first row of cells (those that start in row 1) add up to the box width with the gaps between them; the first column's cells add up to the box height
        $top = array_filter($cells, static fn (array $c): bool => $c['row'] === 1);
        $this->assertEqualsWithDelta($width, array_sum(array_column($top, 'width')) + (count($top) - 1) * $gap, 0.001, 'the widths and the gaps are the box width');

        $left = array_filter($cells, static fn (array $c): bool => $c['column'] === 1);
        $this->assertEqualsWithDelta($height, array_sum(array_column($left, 'height')) + (count($left) - 1) * $gap, 0.001, 'the heights and the gaps are the box height');
    }

    #[DataProvider('grids')]
    public function test_the_even_schemes_have_equal_cells(string $scheme, string $ratio, int $gap): void
    {
        if (! in_array($scheme, ['one', 'two', 'three', 'four', 'six'], true)) {
            $this->addToAssertionCount(1);

            return;
        }

        $cells = G::cells($scheme, $ratio, $gap);

        foreach ($cells as $cell) {
            $this->assertEqualsWithDelta($cells[0]['width'], $cell['width'], 0.001);
            $this->assertEqualsWithDelta($cells[0]['height'], $cell['height'], 0.001);
        }
    }

    #[DataProvider('grids')]
    public function test_the_owners_sketch_has_the_halves_the_tall_cell_and_the_thirds(string $scheme, string $ratio, int $gap): void
    {
        if ($scheme !== 'uneven-a') {
            $this->addToAssertionCount(1);

            return;
        }

        [, $height] = G::box($ratio);
        $by = array_column(G::cells($scheme, $ratio, $gap), null, 'name');

        $this->assertEqualsWithDelta(($height - $gap) / 2, $by['a']['height'], 0.001, 'left: half the height minus the gap');
        $this->assertEqualsWithDelta(($height - $gap) / 2, $by['b']['height'], 0.001);
        $this->assertEqualsWithDelta($height, $by['c']['height'], 0.001, 'middle: the full height');

        foreach (['d', 'e', 'f'] as $name) {
            $this->assertEqualsWithDelta(($height - 2 * $gap) / 3, $by[$name]['height'], 0.001, 'right: a third each');
        }

        $this->assertEqualsWithDelta($by['a']['width'], $by['c']['width'], 0.001, 'three equal columns');
        $this->assertEqualsWithDelta($by['a']['width'], $by['d']['width'], 0.001);
    }

    public function test_two_thirds_cells_and_the_numbers_of_the_default_grid(): void
    {
        $by = array_column(G::cells('uneven-b', '3:2', 0), null, 'name');
        $this->assertEqualsWithDelta(880.0, $by['a']['width'], 0.001);
        $this->assertEqualsWithDelta(880.0, $by['a']['height'], 0.001);
        $this->assertEqualsWithDelta(440.0, $by['b']['width'], 0.001);
        $this->assertEqualsWithDelta(440.0, $by['b']['height'], 0.001);

        $three = G::cells('three', '3:2', 16);
        $this->assertEqualsWithDelta((1320 - 32) / 3, $three[0]['width'], 0.001);
        $this->assertSame(430, $three[0]['rounded_width']);
        $this->assertSame(880, $three[0]['rounded_height']);
        $this->assertSame('1:2', $three[0]['label'], 'a tall cell is named');
    }

    public function test_the_gap_is_taken_off_the_width(): void
    {
        $none = G::cells('three', '16:9', 0)[0]['width'];
        $gapped = G::cells('three', '16:9', 32)[0]['width'];

        $this->assertEqualsWithDelta(440.0, $none, 0.001);
        $this->assertEqualsWithDelta((1320 - 64) / 3, $gapped, 0.001);
    }

    public function test_the_proportion_of_a_cell_is_named_and_the_numbers_are_rounded_to_ten(): void
    {
        $this->assertSame('4:3', G::label(400, 300));
        $this->assertSame('16:9', G::label(1320, 742.5));
        $this->assertSame('1:1', G::label(440, 440));
        $this->assertSame(430, G::tens(429.3));
        $this->assertSame(1330, G::tens(1325.0));
        $this->assertSame([], G::cells('nope', '3:2', 0));
    }
}
