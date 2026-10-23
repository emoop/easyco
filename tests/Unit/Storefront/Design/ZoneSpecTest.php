<?php

namespace Tests\Unit\Storefront\Design;

use App\Storefront\Design\ZoneSpec;
use App\Storefront\Footer\FooterLayout;
use App\Storefront\Header\HeaderCatalog;
use App\Storefront\Header\HeaderLayout;
use InvalidArgumentException;
use Tests\TestCase;

/** DS-1: a zone's shape is read from the constants the existing validators use. */
class ZoneSpecTest extends TestCase
{
    public function test_the_header_desktop_limits_are_the_existing_constants(): void
    {
        $spec = ZoneSpec::for('header.desktop');

        $this->assertSame(['top', 'main', 'bottom'], $spec->rowNames);
        $this->assertSame(HeaderLayout::SLOTS, $spec->cellNames);
        $this->assertSame([3, 3, 3, 3], [$spec->minRows, $spec->maxRows, $spec->minCells, $spec->maxCells]);
        $this->assertSame(HeaderLayout::MAX_PER_SLOT, $spec->maxNodesPerCell);
        $this->assertNull($spec->maxNodes);
        $this->assertSame(['logo' => 1, 'menu_toggle' => 1], $spec->typeLimits);
        $this->assertSame([], $spec->extraCells);
        $this->assertTrue($spec->allows('anything'), 'no allowed list: every type of the component set');
    }

    public function test_the_header_phone_has_two_rows_and_the_drawer_as_an_extra_cell(): void
    {
        $spec = ZoneSpec::for('header.mobile');

        $this->assertSame(array_keys(HeaderLayout::ROW_HEIGHTS['mobile']), $spec->rowNames);
        $this->assertSame(2, $spec->maxRows);
        $this->assertSame(['drawer' => ['max' => HeaderLayout::MAX_DRAWER, 'types' => HeaderCatalog::DRAWER_TYPES]], $spec->extraCells);
    }

    public function test_the_footer_upper_limits_are_the_existing_constants(): void
    {
        $spec = ZoneSpec::for('footer.upper');

        $this->assertNull($spec->rowNames);
        $this->assertNull($spec->cellNames);
        $this->assertSame([1, FooterLayout::MAX_ROWS, 1, FooterLayout::MAX_COLUMNS], [$spec->minRows, $spec->maxRows, $spec->minCells, $spec->maxCells]);
        $this->assertSame([FooterLayout::MAX_BLOCKS_PER_COLUMN, FooterLayout::MAX_BLOCKS], [$spec->maxNodesPerCell, $spec->maxNodes]);
        $this->assertSame([2, 5, 6, 40], [FooterLayout::MAX_ROWS, FooterLayout::MAX_COLUMNS, FooterLayout::MAX_BLOCKS_PER_COLUMN, FooterLayout::MAX_BLOCKS], 'the numbers of FT-1');
    }

    public function test_the_footer_lower_is_one_row_one_cell_one_text_line(): void
    {
        $spec = ZoneSpec::for('footer.lower');

        $this->assertSame(['line'], $spec->rowNames);
        $this->assertSame(['line'], $spec->cellNames);
        $this->assertSame([1, 1, 1, 1, 1], [$spec->minRows, $spec->maxRows, $spec->maxCells, $spec->maxNodesPerCell, $spec->maxNodes]);
        $this->assertTrue($spec->allows('text_line'));
        $this->assertFalse($spec->allows('menu'));
    }

    public function test_every_zone_has_a_spec_and_an_unknown_one_is_refused(): void
    {
        foreach (ZoneSpec::ZONES as $zone) {
            $this->assertSame($zone, ZoneSpec::for($zone)->toArray()['zone']);
        }

        $this->expectException(InvalidArgumentException::class);

        ZoneSpec::for('sidebar');
    }
}
