<?php

namespace App\Storefront\Design;

use InvalidArgumentException;

/**
 * THE GENERIC LAYOUT TREE (DS-1, design-screen-design.md section 1) as plain arrays, with a few helpers the Design screen will call:
 *
 *   Layout { v: 1, variant?: string, props: {...}, rows: [Row] }
 *   Row    { id, props: {...}, cells: [Cell] }
 *   Cell   { id, props: {...}, items: [Node] }
 *   Node   { id, type, settings: {...}, common: {...} }
 *
 * An id is `[a-z0-9-]{1,40}` and is unique in the tree. The helpers never mutate their argument: they return the changed tree (the screen keeps its state as an array).
 */
final class Tree
{
    public const VERSION = 1;

    public static function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[a-z0-9-]{1,40}$/', $id) === 1;
    }

    /** @param  array<string, mixed>  $props  @param  list<array<string, mixed>>  $rows @return array<string, mixed> */
    public static function layout(array $props, array $rows, ?string $variant = null): array
    {
        return ['v' => self::VERSION] + ($variant === null ? [] : ['variant' => $variant]) + ['props' => $props, 'rows' => $rows];
    }

    /** @param  array<string, mixed>  $props  @param  list<array<string, mixed>>  $cells @return array<string, mixed> */
    public static function row(string $id, array $props, array $cells): array
    {
        return ['id' => self::id($id), 'props' => $props, 'cells' => $cells];
    }

    /** @param  array<string, mixed>  $props  @param  list<array<string, mixed>>  $items @return array<string, mixed> */
    public static function cell(string $id, array $props, array $items): array
    {
        return ['id' => self::id($id), 'props' => $props, 'items' => $items];
    }

    /** @param  array<string, mixed>  $settings  @param  array<string, mixed>  $common @return array<string, mixed> */
    public static function node(string $id, string $type, array $settings, array $common = []): array
    {
        return ['id' => self::id($id), 'type' => $type, 'settings' => $settings, 'common' => $common];
    }

    /** Every id of the tree (rows, cells, nodes), in document order. @param  array<string, mixed>  $layout @return list<string> */
    public static function ids(array $layout): array
    {
        $ids = [];

        foreach ($layout['rows'] ?? [] as $row) {
            $ids[] = $row['id'];

            foreach ($row['cells'] ?? [] as $cell) {
                $ids[] = $cell['id'];

                foreach ($cell['items'] ?? [] as $node) {
                    $ids[] = $node['id'];
                }
            }
        }

        return $ids;
    }

    /** The structure check: ids valid and unique, the shape of every level. Returns the problems (empty = fine). @param  array<string, mixed>  $layout @return list<string> */
    public static function problems(array $layout): array
    {
        $problems = [];

        if (($layout['v'] ?? null) !== self::VERSION) {
            $problems[] = 'v';
        }

        $ids = self::ids($layout);

        foreach ($ids as $id) {
            self::validId($id) || $problems[] = 'bad id '.(is_string($id) ? mb_substr($id, 0, 20) : '?');
        }

        foreach (array_keys(array_filter(array_count_values($ids), static fn (int $n): bool => $n > 1)) as $id) {
            $problems[] = 'duplicate id '.$id;
        }

        foreach ($layout['rows'] ?? [] as $row) {
            foreach ($row['cells'] ?? [] as $cell) {
                foreach ($cell['items'] ?? [] as $node) {
                    is_string($node['type'] ?? null) && is_array($node['settings'] ?? null) && is_array($node['common'] ?? null) || $problems[] = 'bad node '.($node['id'] ?? '?');
                }
            }
        }

        return $problems;
    }

    /** @param  array<string, mixed>  $layout @return array<string, mixed>|null the node, or null */
    public static function find(array $layout, string $nodeId): ?array
    {
        foreach ($layout['rows'] ?? [] as $row) {
            foreach ($row['cells'] ?? [] as $cell) {
                foreach ($cell['items'] ?? [] as $node) {
                    if ($node['id'] === $nodeId) {
                        return $node;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Adds a node to the END of a cell (or at `$position`).
     *
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException a cell that does not exist, or an id that is already used
     */
    public static function addNode(array $layout, string $cellId, array $node, ?int $position = null): array
    {
        if (in_array($node['id'] ?? null, self::ids($layout), true)) {
            throw new InvalidArgumentException('The id is already used.');
        }

        $found = false;

        foreach ($layout['rows'] as $r => $row) {
            foreach ($row['cells'] as $c => $cell) {
                if ($cell['id'] === $cellId) {
                    $found = true;
                    array_splice($layout['rows'][$r]['cells'][$c]['items'], $position ?? count($cell['items']), 0, [$node]);
                }
            }
        }

        $found || throw new InvalidArgumentException('There is no such cell.');

        return $layout;
    }

    /** @param  array<string, mixed>  $layout @return array<string, mixed> */
    public static function removeNode(array $layout, string $nodeId): array
    {
        foreach ($layout['rows'] as $r => $row) {
            foreach ($row['cells'] as $c => $cell) {
                $layout['rows'][$r]['cells'][$c]['items'] = array_values(array_filter($cell['items'], static fn (array $node): bool => $node['id'] !== $nodeId));
            }
        }

        return $layout;
    }

    /**
     * Moves a node to a cell (the same or another) at a position: the one operation behind ← → ↑ ↓.
     *
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     */
    public static function moveNode(array $layout, string $nodeId, string $toCellId, int $position): array
    {
        $node = self::find($layout, $nodeId) ?? throw new InvalidArgumentException('There is no such node.');

        return self::addNode(self::removeNode($layout, $nodeId), $toCellId, $node, $position);
    }

    private static function id(string $id): string
    {
        return self::validId($id) ? $id : throw new InvalidArgumentException('An id is 1 to 40 characters of a-z, 0-9 and "-": '.mb_substr($id, 0, 40));
    }
}
