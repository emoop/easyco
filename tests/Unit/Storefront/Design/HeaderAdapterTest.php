<?php

namespace Tests\Unit\Storefront\Design;

use App\Storefront\Design\Tree;
use App\Storefront\Design\ZoneStore\HeaderAdapter;
use App\Storefront\Header\HeaderValidator;
use InvalidArgumentException;
use Tests\Feature\Storefront\Design\DesignFixtures;
use Tests\TestCase;

/** DS-1: the header's stored shape and the generic tree: a strict round trip, deterministic ids, and an edited tree still gives a shape the validator accepts. */
class HeaderAdapterTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function stored(): array
    {
        return ['default' => HeaderValidator::validate(DesignFixtures::headerDefault())->layout, 'large' => HeaderValidator::validate(DesignFixtures::headerLarge())->layout];
    }

    public function test_the_round_trip_is_strict_for_the_default_and_the_large_header(): void
    {
        foreach ($this->stored() as $name => $stored) {
            $this->assertSame($stored, HeaderAdapter::fromTree(HeaderAdapter::toTree($stored)), $name);
        }
    }

    public function test_the_ids_are_deterministic_valid_and_unique(): void
    {
        $stored = $this->stored()['large'];
        $tree = HeaderAdapter::toTree($stored);

        $this->assertSame($tree, HeaderAdapter::toTree($stored), 'the same stored JSON, the same tree');
        $this->assertSame([], Tree::problems($tree['desktop']));
        $this->assertSame([], Tree::problems($tree['mobile']));

        $desktop = Tree::ids($tree['desktop']);
        $this->assertContains('h-d-main', $desktop);
        $this->assertContains('h-d-main-left', $desktop);
        $this->assertContains('h-d-main-left-0', $desktop);
        $this->assertContains('h-d-bottom-right-0', $desktop);

        $mobile = Tree::ids($tree['mobile']);
        $this->assertContains('h-m-drawer-r', $mobile);
        $this->assertContains('h-m-drawer', $mobile);
        $this->assertContains('h-m-drawer-0', $mobile);
        $this->assertContains('h-m-drawer-4', $mobile, 'five drawer elements');
        $this->assertNotContains('h-m-bottom', $mobile, 'the phone has no bottom row');
    }

    public function test_the_props_are_what_the_stored_data_has_and_nothing_more(): void
    {
        $tree = HeaderAdapter::toTree($this->stored()['large']);

        $this->assertSame(['sticky' => false], $tree['desktop']['props']);
        $this->assertSame(['sticky' => true, 'drawer_side' => 'right', 'drawer_upper' => false], $tree['mobile']['props']);
        $this->assertSame('desktop', $tree['desktop']['variant']);
        $this->assertSame(['enabled' => true, 'height' => 80], $tree['desktop']['rows'][1]['props']);
        $this->assertSame([], $tree['desktop']['rows'][1]['cells'][0]['props']);
        $this->assertSame(['id' => 'h-d-main-left-0', 'type' => 'menu', 'settings' => ['style' => 'dropdown'], 'common' => []], $tree['desktop']['rows'][1]['cells'][0]['items'][0]);
    }

    public function test_a_node_moved_added_and_removed_in_the_tree_gives_a_shape_the_validator_accepts(): void
    {
        $stored = $this->stored()['large'];
        $tree = HeaderAdapter::toTree($stored);

        // move the cart (main right, second) to the start of the top row's left cell
        $tree['desktop'] = Tree::moveNode($tree['desktop'], 'h-d-main-right-1', 'h-d-top-left', 0);
        // add a spacer to the bottom row's right cell and remove the bottom text
        $tree['desktop'] = Tree::addNode($tree['desktop'], 'h-d-bottom-right', Tree::node('n-spacer-2', 'spacer', ['width' => 'lg']));
        $tree['desktop'] = Tree::removeNode($tree['desktop'], 'h-d-bottom-center-0');
        // the phone: a text goes into the drawer
        $tree['mobile'] = Tree::addNode($tree['mobile'], 'h-m-drawer', Tree::node('n-drawer-text', 'text', ['text' => 'Hello', 'url' => '']));

        $changed = HeaderAdapter::fromTree($tree);
        $validation = HeaderValidator::validate($changed);

        $this->assertSame([], $validation->errors);
        $this->assertSame($changed, $validation->layout, 'already the normalised shape');
        $this->assertSame('cart', $changed['desktop']['rows']['top']['left'][0]['type']);
        $this->assertSame(['spacer', 'spacer'], array_column($changed['desktop']['rows']['bottom']['right'], 'type'));
        $this->assertSame([], $changed['desktop']['rows']['bottom']['center']);
        $this->assertSame('text', end($changed['mobile']['drawer'])['type']);
        $this->assertCount(2, $changed['desktop']['rows']['main']['right'], 'one left the main row');
    }

    public function test_a_tree_that_breaks_a_limit_is_still_refused_by_the_validator(): void
    {
        $tree = HeaderAdapter::toTree($this->stored()['default']);

        for ($i = 0; $i < 7; $i++) {
            $tree['desktop'] = Tree::addNode($tree['desktop'], 'h-d-top-left', Tree::node("extra-{$i}", 'spacer', ['width' => 'sm']));
        }

        $validation = HeaderValidator::validate(HeaderAdapter::fromTree($tree));

        $this->assertArrayHasKey('desktop.rows.top.left', $validation->errors);
    }

    public function test_a_tree_that_is_not_the_headers_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HeaderAdapter::fromTree(['desktop' => Tree::layout([], []), 'mobile' => Tree::layout([], [])]);
    }

    // ---- DS-2a: the look of a row (bg, fg, text_size) and locate() ---------------------------------------------------------------------------

    /** @return array<string, mixed> the large header with a look on three rows (the keys stand after `height`, where the validator puts them) */
    private function withLook(): array
    {
        $stored = $this->stored()['large'];
        $put = static function (array $row, array $look): array {
            return array_slice($row, 0, 2, true) + $look + array_slice($row, 2, null, true);
        };
        $stored['desktop']['rows']['top'] = $put($stored['desktop']['rows']['top'], ['bg' => '#112233', 'fg' => '#eeeeee', 'text_size' => 'l']);
        $stored['desktop']['rows']['main'] = $put($stored['desktop']['rows']['main'], ['bg' => '#ffffff']);
        $stored['mobile']['rows']['top'] = $put($stored['mobile']['rows']['top'], ['text_size' => 's']);

        return $stored;
    }

    public function test_the_look_of_a_row_round_trips_and_is_the_colors_group_and_text_size(): void
    {
        $validation = HeaderValidator::validate($this->withLook());
        $this->assertSame([], $validation->errors);
        $this->assertSame($this->withLook(), $validation->layout, 'the validator keeps the look in this order');
        $tree = HeaderAdapter::toTree($validation->layout);

        $this->assertSame(['enabled' => true, 'height' => $validation->layout['desktop']['rows']['top']['height'], 'colors' => ['bg' => '#112233', 'fg' => '#eeeeee'], 'text_size' => 'l'], $tree['desktop']['rows'][0]['props']);
        $this->assertSame(['bg' => '#ffffff'], $tree['desktop']['rows'][1]['props']['colors'], 'one colour only');
        $this->assertArrayNotHasKey('text_size', $tree['desktop']['rows'][1]['props']);
        $this->assertArrayNotHasKey('colors', $tree['desktop']['rows'][2]['props'], 'nothing chosen: nothing added');
        $this->assertSame($validation->layout, HeaderAdapter::fromTree($tree));
    }

    public function test_a_row_without_a_look_gets_no_look_keys_from_the_adapter(): void
    {
        $tree = HeaderAdapter::toTree($this->stored()['default']);

        foreach (['desktop', 'mobile'] as $variant) {
            foreach ($tree[$variant]['rows'] as $row) {
                $this->assertSame($row['id'] === 'h-m-drawer-r' ? [] : ['enabled', 'height'], array_keys($row['props']), $row['id']);
            }
        }
    }

    public function test_a_look_edited_in_the_tree_is_stored_and_an_emptied_one_is_dropped(): void
    {
        $tree = HeaderAdapter::toTree($this->stored()['default']);
        $tree['desktop']['rows'][1]['props']['colors'] = ['bg' => '#abcdef'];
        $tree['desktop']['rows'][1]['props']['text_size'] = 'm';
        $tree['desktop']['rows'][0]['props']['colors'] = ['bg' => '', 'fg' => null];
        $tree['desktop']['rows'][0]['props']['text_size'] = '';

        $stored = HeaderAdapter::fromTree($tree);

        $this->assertSame(['enabled', 'height', 'bg', 'text_size', 'left', 'center', 'right'], array_keys($stored['desktop']['rows']['main']));
        $this->assertSame(['enabled', 'height', 'left', 'center', 'right'], array_keys($stored['desktop']['rows']['top']));
        $this->assertSame([], HeaderValidator::validate($stored)->errors);
    }

    public function test_locate_finds_the_node_of_a_setting_path(): void
    {
        $tree = HeaderAdapter::toTree($this->stored()['large']);

        $this->assertSame(['kind' => 'node', 'id' => 'h-d-main-left-0', 'field' => 'style'], HeaderAdapter::locate($tree, 'desktop.rows.main.left.0.settings.style'));
        $this->assertSame(['kind' => 'node', 'id' => 'h-d-main-left-0', 'field' => 'type'], HeaderAdapter::locate($tree, 'desktop.rows.main.left.0.type'));
        $this->assertSame(['kind' => 'node', 'id' => 'h-d-main-left-0', 'field' => null], HeaderAdapter::locate($tree, 'desktop.rows.main.left.0'));
    }

    public function test_locate_finds_a_list_inside_a_node_and_the_drawer(): void
    {
        $tree = HeaderAdapter::toTree($this->stored()['large']);
        $social = null;

        foreach ($tree['desktop']['rows'] as $row) {
            foreach ($row['cells'] as $cell) {
                foreach ($cell['items'] as $node) {
                    $node['type'] === 'social' && $social = $node['id'];
                }
            }
        }

        $this->assertNotNull($social, 'the large fixture has a social element');
        [, , $rowName, $slot, $index] = explode('-', $social);
        $this->assertSame(['kind' => 'node', 'id' => $social, 'field' => 'items.1.url'], HeaderAdapter::locate($tree, "desktop.rows.{$rowName}.{$slot}.{$index}.settings.items.1.url"));

        $this->assertSame(['kind' => 'node', 'id' => 'h-m-drawer-0', 'field' => 'style'], HeaderAdapter::locate($tree, 'mobile.drawer.0.settings.style'));
        $this->assertSame(['kind' => 'cell', 'id' => 'h-m-drawer', 'field' => null], HeaderAdapter::locate($tree, 'mobile.drawer'));
    }

    public function test_locate_finds_rows_cells_and_layouts(): void
    {
        $tree = HeaderAdapter::toTree($this->stored()['large']);

        $this->assertSame(['kind' => 'row', 'id' => 'h-d-main', 'field' => 'height'], HeaderAdapter::locate($tree, 'desktop.rows.main.height'));
        $this->assertSame(['kind' => 'row', 'id' => 'h-m-top', 'field' => 'enabled'], HeaderAdapter::locate($tree, 'mobile.rows.top.enabled'));
        $this->assertSame(['kind' => 'row', 'id' => 'h-d-top', 'field' => 'colors.bg'], HeaderAdapter::locate($tree, 'desktop.rows.top.bg'));
        $this->assertSame(['kind' => 'row', 'id' => 'h-d-top', 'field' => 'colors.fg'], HeaderAdapter::locate($tree, 'desktop.rows.top.fg'));
        $this->assertSame(['kind' => 'row', 'id' => 'h-d-top', 'field' => 'text_size'], HeaderAdapter::locate($tree, 'desktop.rows.top.text_size'));
        $this->assertSame(['kind' => 'row', 'id' => 'h-d-bottom', 'field' => null], HeaderAdapter::locate($tree, 'desktop.rows.bottom'));
        $this->assertSame(['kind' => 'cell', 'id' => 'h-d-main-center', 'field' => null], HeaderAdapter::locate($tree, 'desktop.rows.main.center'));
        $this->assertSame(['kind' => 'layout', 'id' => 'desktop', 'field' => 'sticky'], HeaderAdapter::locate($tree, 'desktop.sticky'));
        $this->assertSame(['kind' => 'layout', 'id' => 'mobile', 'field' => 'drawer_side'], HeaderAdapter::locate($tree, 'mobile.drawer_side'));
        $this->assertSame(['kind' => 'layout', 'id' => 'mobile', 'field' => 'drawer_upper'], HeaderAdapter::locate($tree, 'mobile.drawer_upper'));
        $this->assertSame(['kind' => 'layout', 'id' => 'desktop', 'field' => null], HeaderAdapter::locate($tree, 'desktop.rows'), 'the layout-wide rules (one logo)');
        $this->assertSame(['kind' => 'layout', 'id' => 'desktop', 'field' => null], HeaderAdapter::locate($tree, 'layout'));
    }

    public function test_locate_returns_null_for_a_path_that_points_at_nothing(): void
    {
        $tree = HeaderAdapter::toTree($this->stored()['default']);

        foreach (['', 'tablet.rows.main', 'desktop', 'desktop.rows.nope.height', 'mobile.rows.bottom.height'] as $path) {
            $this->assertNull(HeaderAdapter::locate($tree, $path), $path);
        }

        $this->assertSame(['kind' => 'cell', 'id' => 'h-d-main-left', 'field' => null], HeaderAdapter::locate($tree, 'desktop.rows.main.left.9.settings.x'), 'an index with no node falls back to its cell');
    }
}
