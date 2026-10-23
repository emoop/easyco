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
}
