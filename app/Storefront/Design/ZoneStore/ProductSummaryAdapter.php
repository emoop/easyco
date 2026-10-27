<?php

namespace App\Storefront\Design\ZoneStore;

use App\Storefront\Design\Tree;
use InvalidArgumentException;

/**
 * The right column's STORED shape (SC-1: `design.product_summary.global.draft|published`, `{components: [{type, settings}]}`) <-> the generic layout tree (DS-1). Pure functions: no setting is read or
 * written here. The column is ONE layout `main` with ONE row and ONE cell (the screen hides that structure): row `s-r0`, cell `s-r0-c0`, node `s-r0-c0-{position}`. Nothing is invented:
 * `fromTree(toTree($x)) === $x`.
 */
final class ProductSummaryAdapter
{
    public const ROW = 's-r0';

    public const CELL = 's-r0-c0';

    /**
     * @param  array{components: list<array{type: string, settings: array<string, mixed>}>}  $stored  a VALIDATED layout
     * @return array{main: array<string, mixed>}
     */
    public static function toTree(array $stored): array
    {
        $nodes = [];

        foreach (array_values($stored['components']) as $k => $component) {
            $nodes[] = Tree::node(self::CELL.'-'.$k, $component['type'], $component['settings']);
        }

        return ['main' => Tree::layout([], [Tree::row(self::ROW, [], [Tree::cell(self::CELL, [], $nodes)])], 'main')];
    }

    /**
     * @param  array{main: array<string, mixed>}  $tree
     * @return array{components: list<array{type: string, settings: array<string, mixed>}>} the stored shape (what ProductSummaryValidator accepts when the tree holds valid settings)
     *
     * @throws InvalidArgumentException a tree that is not the right column's
     */
    public static function fromTree(array $tree): array
    {
        $problems = Tree::problems($tree['main'] ?? []);
        $cell = $tree['main']['rows'][0]['cells'][0] ?? null;

        if (! isset($tree['main']) || $problems !== [] || $cell === null) {
            throw new InvalidArgumentException('The right column tree is not valid: '.implode(', ', $problems));
        }

        return ['components' => array_map(
            static fn (array $node): array => ['type' => $node['type'], 'settings' => $node['settings']],
            array_values($cell['items']),
        )];
    }

    /**
     * Where a validator path (`components`, `components.2`, `components.2.settings.html_block`) lives in the tree: the node, or the one cell (the list as a whole). Null when it points at nothing.
     *
     * @param  array{main: array<string, mixed>}  $tree
     * @return array{kind: string, id: string, field: ?string}|null
     */
    public static function locate(array $tree, string $path): ?array
    {
        $parts = explode('.', $path);
        $items = $tree['main']['rows'][0]['cells'][0]['items'] ?? [];

        if ($parts[0] !== 'components') {
            return null;
        }

        if (count($parts) === 1 || ! ctype_digit($parts[1])) {
            return ['kind' => 'cell', 'id' => self::CELL, 'field' => null];
        }

        $node = $items[(int) $parts[1]] ?? null;

        if ($node === null) {
            return ['kind' => 'cell', 'id' => self::CELL, 'field' => null];
        }

        $field = ($parts[2] ?? '') === 'settings' && count($parts) > 3 ? implode('.', array_slice($parts, 3)) : null;

        return ['kind' => 'node', 'id' => $node['id'], 'field' => $field];
    }
}
