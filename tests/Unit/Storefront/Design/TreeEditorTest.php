<?php

namespace Tests\Unit\Storefront\Design;

use App\Storefront\Design\Tree;
use App\Storefront\Design\TreeEditor;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * DS-2a: the pure editing operations of the Design screen. A layout of two rows: row `a` with cells `a-l`, `a-c`, `a-r`, row `b` with cells `b-l`, `b-c`, `b-r`, and a lone drawer-like row `d` with
 * one cell. Moves go one place within a cell and to the END of the neighbouring cell of the SAME row; nothing leaves its row.
 */
class TreeEditorTest extends TestCase
{
    /** @return array<string, mixed> */
    private function layout(): array
    {
        $cell = static fn (string $id, array $nodes): array => Tree::cell($id, [], array_map(static fn (string $n): array => Tree::node($n, 'text', ['text' => $n]), $nodes));

        return Tree::layout([], [
            Tree::row('a', [], [$cell('a-l', ['n1', 'n2', 'n3']), $cell('a-c', ['n4']), $cell('a-r', [])]),
            Tree::row('b', [], [$cell('b-l', ['n5']), $cell('b-c', []), $cell('b-r', ['n6'])]),
            Tree::row('d', [], [$cell('d-c', ['n7', 'n8'])]),
        ]);
    }

    /** @param  array<string, mixed>  $layout  @return array<string, list<string>> cell id => node ids */
    private function cells(array $layout): array
    {
        $out = [];

        foreach ($layout['rows'] as $row) {
            foreach ($row['cells'] as $cell) {
                $out[$cell['id']] = array_column($cell['items'], 'id');
            }
        }

        return $out;
    }

    public function test_it_finds_nodes_cells_rows_and_positions(): void
    {
        $layout = $this->layout();

        $this->assertSame('n2', TreeEditor::node($layout, 'n2')['id']);
        $this->assertNull(TreeEditor::node($layout, 'zz'));
        $this->assertSame('a-c', TreeEditor::cell($layout, 'a-c')['id']);
        $this->assertNull(TreeEditor::cell($layout, 'a'));
        $this->assertSame('b', TreeEditor::row($layout, 'b')['id']);
        $this->assertSame(['row' => 1, 'cell' => 2, 'index' => 0], TreeEditor::position($layout, 'n6'));
        $this->assertSame('a-l', TreeEditor::cellOf($layout, 'n3'));
        $this->assertNull(TreeEditor::cellOf($layout, 'zz'));
    }

    public function test_add_appends_to_a_cell_and_refuses_an_unknown_cell_or_a_used_id(): void
    {
        $layout = $this->layout();
        $added = TreeEditor::add($layout, 'a-r', Tree::node('n9', 'text', []));

        $this->assertSame(['n9'], $this->cells($added)['a-r']);
        $this->assertSame($this->cells($this->layout()), $this->cells($layout), 'the argument is not changed');
        $this->assertSame(['n1', 'n2', 'n3', 'n9'], $this->cells(TreeEditor::add($layout, 'a-l', Tree::node('n9', 'text', [])))['a-l']);

        foreach ([['nope', 'n10'], ['a-r', 'n1']] as [$cell, $id]) {
            try {
                TreeEditor::add($layout, $cell, Tree::node($id, 'text', []));
                $this->fail('refused');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_remove_takes_a_node_out_and_refuses_an_unknown_one(): void
    {
        $removed = TreeEditor::remove($this->layout(), 'n2');

        $this->assertSame(['n1', 'n3'], $this->cells($removed)['a-l']);

        $this->expectException(InvalidArgumentException::class);
        TreeEditor::remove($this->layout(), 'zz');
    }

    public function test_up_and_down_move_one_place_inside_the_cell(): void
    {
        $this->assertSame(['n2', 'n1', 'n3'], $this->cells(TreeEditor::move($this->layout(), 'n2', 'up'))['a-l']);
        $this->assertSame(['n1', 'n3', 'n2'], $this->cells(TreeEditor::move($this->layout(), 'n2', 'down'))['a-l']);
        $this->assertFalse(TreeEditor::canMove($this->layout(), 'n1', 'up'));
        $this->assertFalse(TreeEditor::canMove($this->layout(), 'n3', 'down'));
        $this->assertTrue(TreeEditor::canMove($this->layout(), 'n2', 'up'));
    }

    public function test_left_and_right_go_to_the_end_of_the_neighbouring_cell_of_the_same_row(): void
    {
        $right = $this->cells(TreeEditor::move($this->layout(), 'n2', 'right'));
        $this->assertSame(['n1', 'n3'], $right['a-l']);
        $this->assertSame(['n4', 'n2'], $right['a-c']);

        $left = $this->cells(TreeEditor::move($this->layout(), 'n4', 'left'));
        $this->assertSame(['n1', 'n2', 'n3', 'n4'], $left['a-l']);
        $this->assertSame([], $left['a-c']);
    }

    public function test_it_refuses_a_move_with_nowhere_to_go(): void
    {
        $layout = $this->layout();

        foreach ([['n1', 'left'], ['n6', 'right'], ['n7', 'left'], ['n7', 'right'], ['n7', 'up'], ['n8', 'down'], ['n1', 'sideways'], ['zz', 'up']] as [$id, $direction]) {
            $this->assertFalse(TreeEditor::canMove($layout, $id, $direction), "{$id} {$direction}");

            try {
                TreeEditor::move($layout, $id, $direction);
                $this->fail("{$id} {$direction} was moved");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_left_never_crosses_into_the_previous_row_and_right_never_into_the_next(): void
    {
        $layout = $this->layout();

        // n5 is the first cell of row b: row a's last cell must not take it; n3's row a is above and its last cell is `a-r` (empty)
        $this->assertFalse(TreeEditor::canMove($layout, 'n5', 'left'));
        $this->assertFalse(TreeEditor::canMove($layout, 'n6', 'right'));
        $this->assertSame($this->cells($layout), $this->cells(TreeEditor::move(TreeEditor::move($layout, 'n6', 'left'), 'n6', 'right')), 'left then right is back where it was');
    }

    public function test_settings_and_row_props_are_replaced_and_unknown_ids_refused(): void
    {
        $layout = TreeEditor::setSettings($this->layout(), 'n1', ['text' => 'changed']);
        $this->assertSame(['text' => 'changed'], TreeEditor::node($layout, 'n1')['settings']);
        $this->assertSame(['text' => 'n2'], TreeEditor::node($layout, 'n2')['settings']);

        $layout = TreeEditor::setRowProps($layout, 'b', ['enabled' => true]);
        $this->assertSame(['enabled' => true], TreeEditor::row($layout, 'b')['props']);
        $this->assertSame([], TreeEditor::row($layout, 'a')['props']);

        foreach ([fn () => TreeEditor::setSettings($this->layout(), 'zz', []), fn () => TreeEditor::setRowProps($this->layout(), 'zz', [])] as $call) {
            try {
                $call();
                $this->fail('refused');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
