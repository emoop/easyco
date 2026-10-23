<?php

namespace App\Storefront\Design\ZoneStore;

use App\Storefront\Design\Tree;
use InvalidArgumentException;

/**
 * The footer's STORED shape (FT-1: `footer.layout.draft|published`) <-> the generic layout tree (DS-1). Pure functions: no setting is read or written here.
 *
 * Two layouts: `upper` (rows of cells of blocks) and `lower` (ONE row, ONE cell, ONE `text_line` node holding the template). Ids are deterministic from the position (indexes start at 0):
 * row `f-u-r1`, cell `f-u-r1-c2`, node `f-u-r1-c2-0`; the lower line is `f-l-r0` / `f-l-r0-c0` / `f-l-r0-c0-0`.
 * A cell carries the stored `span` and `align` as its props. The level colours (`bg`, `text`) are the `colors` group of the design document (`bg`, `fg`) in the layout props; the border
 * (`border_color`, `border_width`), the `padding` and `enabled` are layout props as stored. The lower line's `align` is the `align` group of its node (the default `start` is not stored,
 * as the group rule says; reading it back gives `start`). Nothing is invented: `fromTree(toTree($x)) === $x`.
 */
final class FooterAdapter
{
    /**
     * @param  array<string, mixed>  $stored  a VALIDATED footer layout
     * @return array{upper: array<string, mixed>, lower: array<string, mixed>}
     */
    public static function toTree(array $stored): array
    {
        $upper = $stored['upper'];
        $rows = [];

        foreach (array_values($upper['rows']) as $r => $row) {
            $cells = [];

            foreach (array_values($row['columns']) as $c => $column) {
                $cell = "f-u-r{$r}-c{$c}";
                $nodes = [];

                foreach (array_values($column['blocks']) as $k => $block) {
                    $nodes[] = Tree::node("{$cell}-{$k}", $block['type'], $block['settings']);
                }

                $cells[] = Tree::cell($cell, ['span' => $column['span'], 'align' => $column['align']], $nodes);
            }

            $rows[] = Tree::row("f-u-r{$r}", [], $cells);
        }

        $lower = $stored['lower'];
        $line = Tree::node('f-l-r0-c0-0', 'text_line', ['text_template' => $lower['text_template']], $lower['align'] === 'start' ? [] : ['align' => $lower['align']]);

        return [
            'upper' => Tree::layout(['enabled' => $upper['enabled'], 'padding' => $upper['padding'], 'border_color' => $upper['border_color'], 'border_width' => $upper['border_width']] + self::colors($upper), $rows, 'upper'),
            'lower' => Tree::layout(['enabled' => $lower['enabled'], 'padding' => $lower['padding']] + self::colors($lower), [Tree::row('f-l-r0', [], [Tree::cell('f-l-r0-c0', [], [$line])])], 'lower'),
        ];
    }

    /**
     * @param  array{upper: array<string, mixed>, lower: array<string, mixed>}  $tree
     * @return array<string, mixed> the stored shape (what FooterValidator accepts when the tree holds valid settings)
     *
     * @throws InvalidArgumentException a tree that is not the footer's
     */
    public static function fromTree(array $tree): array
    {
        foreach (['upper', 'lower'] as $level) {
            $problems = Tree::problems($tree[$level] ?? []);

            if (! isset($tree[$level]) || $problems !== []) {
                throw new InvalidArgumentException("The footer tree has no valid {$level} layout: ".implode(', ', $problems));
            }
        }

        $u = $tree['upper'];
        $rows = [];

        foreach ($u['rows'] as $row) {
            $columns = [];

            foreach ($row['cells'] as $cell) {
                $columns[] = [
                    'span' => $cell['props']['span'], 'align' => $cell['props']['align'],
                    'blocks' => array_map(static fn (array $node): array => ['type' => $node['type'], 'settings' => $node['settings']], array_values($cell['items'])),
                ];
            }

            $rows[] = ['columns' => $columns];
        }

        $l = $tree['lower'];
        $line = $l['rows'][0]['cells'][0]['items'][0] ?? throw new InvalidArgumentException('The lower line has no node.');

        return [
            'upper' => [
                'enabled' => $u['props']['enabled'], 'padding' => $u['props']['padding'], 'bg' => $u['props']['colors']['bg'] ?? null, 'text' => $u['props']['colors']['fg'] ?? null,
                'border_color' => $u['props']['border_color'], 'border_width' => $u['props']['border_width'], 'rows' => $rows,
            ],
            'lower' => [
                'enabled' => $l['props']['enabled'], 'padding' => $l['props']['padding'], 'bg' => $l['props']['colors']['bg'] ?? null, 'text' => $l['props']['colors']['fg'] ?? null,
                'align' => $line['common']['align'] ?? 'start', 'text_template' => $line['settings']['text_template'],
            ],
        ];
    }

    /** @param  array<string, mixed>  $level  @return array<string, array<string, string>> the `colors` group: only the colours that are set */
    private static function colors(array $level): array
    {
        $colors = array_filter(['bg' => $level['bg'], 'fg' => $level['text']], static fn ($color): bool => $color !== null);

        return $colors === [] ? [] : ['colors' => $colors];
    }
}
