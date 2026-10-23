<?php

namespace Tests\Unit\Storefront\Design;

use App\Storefront\Design\Tree;
use App\Storefront\Design\ZoneStore\FooterAdapter;
use App\Storefront\Footer\FooterValidator;
use InvalidArgumentException;
use Tests\Feature\Storefront\Design\DesignFixtures;
use Tests\TestCase;

/** DS-1: the footer's stored shape and the generic tree: a strict round trip, deterministic ids, the colours as the `colors` group, an edited tree accepted by the validator. */
class FooterAdapterTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function stored(): array
    {
        return ['default' => FooterValidator::validate(DesignFixtures::footerDefault())->layout, 'large' => FooterValidator::validate(DesignFixtures::footerLarge())->layout];
    }

    public function test_the_round_trip_is_strict_for_the_default_and_the_large_footer(): void
    {
        foreach ($this->stored() as $name => $stored) {
            $this->assertSame($stored, FooterAdapter::fromTree(FooterAdapter::toTree($stored)), $name);
        }
    }

    public function test_the_round_trip_keeps_a_lower_line_aligned_to_the_start_and_one_without_colours(): void
    {
        $stored = $this->stored()['large'];
        $stored['lower']['align'] = 'start';
        $stored['lower']['bg'] = null;
        $stored['upper']['text'] = null;

        $this->assertSame($stored, FooterAdapter::fromTree(FooterAdapter::toTree($stored)));
    }

    public function test_the_ids_are_deterministic_valid_and_unique(): void
    {
        $stored = $this->stored()['large'];
        $tree = FooterAdapter::toTree($stored);

        $this->assertSame($tree, FooterAdapter::toTree($stored));
        $this->assertSame([], Tree::problems($tree['upper']));
        $this->assertSame([], Tree::problems($tree['lower']));

        $ids = Tree::ids($tree['upper']);
        foreach (['f-u-r0', 'f-u-r1', 'f-u-r1-c1', 'f-u-r1-c1-0', 'f-u-r0-c0-2', 'f-u-r0-c4-0'] as $id) {
            $this->assertContains($id, $ids);
        }

        $this->assertSame(['f-l-r0', 'f-l-r0-c0', 'f-l-r0-c0-0'], Tree::ids($tree['lower']));
        $this->assertSame('text_line', $tree['lower']['rows'][0]['cells'][0]['items'][0]['type']);
    }

    public function test_the_level_colours_are_the_colors_group_and_nothing_else_is_added(): void
    {
        $tree = FooterAdapter::toTree($this->stored()['large']);

        $this->assertSame(['enabled' => true, 'padding' => 'spacious', 'border_color' => '#c0392b', 'border_width' => 3, 'colors' => ['bg' => '#112233', 'fg' => '#eeeeee']], $tree['upper']['props']);
        $this->assertSame(['enabled' => true, 'padding' => 'normal', 'colors' => ['bg' => '#000000', 'fg' => '#dddddd']], $tree['lower']['props']);
        $this->assertSame(['span' => 2, 'align' => 'center'], $tree['upper']['rows'][0]['cells'][2]['props']);
        $this->assertSame([], $tree['lower']['rows'][0]['cells'][0]['items'][0]['common'], 'align start is the default of the group: not stored');

        $default = FooterAdapter::toTree($this->stored()['default']);
        $this->assertArrayNotHasKey('colors', $default['upper']['props'], 'no colour chosen: no group');
        $this->assertSame(['align' => 'center'], $default['lower']['rows'][0]['cells'][0]['items'][0]['common']);
    }

    public function test_a_node_moved_added_and_removed_in_the_tree_gives_a_shape_the_validator_accepts(): void
    {
        $tree = FooterAdapter::toTree($this->stored()['large']);

        // the legal menu (row 0, column 3) goes to the end of the second row's first column; a spacer is added to the newsletter column; the social block is removed
        $tree['upper'] = Tree::moveNode($tree['upper'], 'f-u-r0-c3-0', 'f-u-r1-c0', 1);
        $tree['upper'] = Tree::addNode($tree['upper'], 'f-u-r0-c4', Tree::node('n-sp', 'spacer', ['size' => 'm']));
        $tree['upper'] = Tree::removeNode($tree['upper'], 'f-u-r1-c0-0');

        $changed = FooterAdapter::fromTree($tree);
        $validation = FooterValidator::validate($changed);

        $this->assertSame([], $validation->errors);
        $this->assertSame($changed, $validation->layout);
        $this->assertSame(['menu'], array_column($changed['upper']['rows'][1]['columns'][0]['blocks'], 'type'));
        $this->assertSame([], $changed['upper']['rows'][0]['columns'][3]['blocks']);
        $this->assertSame(['newsletter', 'spacer'], array_column($changed['upper']['rows'][0]['columns'][4]['blocks'], 'type'));
    }

    public function test_a_tree_that_breaks_a_limit_is_still_refused_by_the_validator(): void
    {
        $tree = FooterAdapter::toTree($this->stored()['default']);

        for ($i = 0; $i < 6; $i++) {
            $tree['upper'] = Tree::addNode($tree['upper'], 'f-u-r0-c0', Tree::node("extra-{$i}", 'spacer', ['size' => 's']));
        }

        $this->assertArrayHasKey('upper.rows.0.columns.0.blocks', FooterValidator::validate(FooterAdapter::fromTree($tree))->errors, 'seven blocks in a column');
    }

    public function test_a_tree_that_is_not_the_footers_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FooterAdapter::fromTree(['upper' => Tree::layout([], [])]);
    }
}
