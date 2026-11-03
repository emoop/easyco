<?php

namespace App\Storefront\Design\ZoneStore;

use App\Storefront\Design\Tree;
use App\Storefront\Home\HomeCatalog;
use InvalidArgumentException;

/**
 * The home layout's STORED shape (HP-1: `design.home.global.draft|published`, `{spacing, sections: [{type, settings}]}`) <-> the generic layout tree (DS-1). Pure functions: no setting is read or written
 * here. The list is ONE layout `main` with ONE row and ONE cell (the screen hides that structure; the zone-wide spacing is the layout's own `props.spacing`): row `h-r0`, cell `h-r0-c0`, node
 * `h-r0-c0-{position}`. Nothing is invented: `fromTree(toTree($x)) === $x`.
 */
final class HomeAdapter
{
    public const ROW = 'h-r0';

    public const CELL = 'h-r0-c0';

    /**
     * @param  array{spacing: string, sections: list<array{type: string, settings: array<string, mixed>}>}  $stored  a VALIDATED layout
     * @return array{main: array<string, mixed>}
     */
    public static function toTree(array $stored): array
    {
        $nodes = [];

        foreach (array_values($stored['sections']) as $k => $section) {
            $nodes[] = Tree::node(self::CELL.'-'.$k, $section['type'], $section['settings']);
        }

        return ['main' => Tree::layout(['spacing' => $stored['spacing']], [Tree::row(self::ROW, [], [Tree::cell(self::CELL, [], $nodes)])], 'main')];
    }

    /**
     * @param  array{main: array<string, mixed>}  $tree
     * @return array{spacing: string, sections: list<array{type: string, settings: array<string, mixed>}>} the stored shape (what HomeValidator accepts when the tree holds valid settings)
     *
     * @throws InvalidArgumentException a tree that is not the home layout's
     */
    public static function fromTree(array $tree): array
    {
        $problems = Tree::problems($tree['main'] ?? []);
        $cell = $tree['main']['rows'][0]['cells'][0] ?? null;

        if (! isset($tree['main']) || $problems !== [] || $cell === null) {
            throw new InvalidArgumentException('The home tree is not valid: '.implode(', ', $problems));
        }

        return [
            'spacing' => $tree['main']['props']['spacing'] ?? HomeCatalog::DEFAULT_SPACING,
            'sections' => array_map(
                static fn (array $node): array => ['type' => $node['type'], 'settings' => $node['settings']],
                array_values($cell['items']),
            ),
        ];
    }

    /**
     * Where a validator path (`spacing`, `sections`, `sections.2`, `sections.2.settings.text`) lives in the tree: the layout (the spacing), the node, or the one cell (the list as a whole).
     *
     * @param  array{main: array<string, mixed>}  $tree
     * @return array{kind: string, id: string, field: ?string}|null
     */
    public static function locate(array $tree, string $path): ?array
    {
        $parts = explode('.', $path);
        $items = $tree['main']['rows'][0]['cells'][0]['items'] ?? [];

        if ($parts[0] === 'spacing') {
            return ['kind' => 'layout', 'id' => 'main', 'field' => 'spacing'];
        }

        if ($parts[0] !== 'sections') {
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
