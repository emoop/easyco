<?php

namespace App\Storefront\Design;

use InvalidArgumentException;

/**
 * The editing operations of the Design screen on ONE layout tree (DS-2a): find, add, remove and the four moves behind ← → ↑ ↓. Pure arrays in, arrays out: no Livewire, no Filament, no
 * validation of limits (the screen validates the whole layout through the zone's validator after each change). Every operation refuses what does not exist by throwing, and never changes
 * its argument.
 *
 *   up / down     = one place earlier / later in the same cell
 *   left / right  = to the END of the previous / next cell of the SAME row (never to another row, never out of a cell that has no neighbour: the drawer is a row of one cell)
 */
final class TreeEditor
{
    public const DIRECTIONS = ['up', 'down', 'left', 'right'];

    /** @param  array<string, mixed>  $layout  @return array{row: int, cell: int, index: int}|null where a node is */
    public static function position(array $layout, string $nodeId): ?array
    {
        foreach ($layout['rows'] ?? [] as $r => $row) {
            foreach ($row['cells'] ?? [] as $c => $cell) {
                foreach ($cell['items'] ?? [] as $i => $node) {
                    if ($node['id'] === $nodeId) {
                        return ['row' => $r, 'cell' => $c, 'index' => $i];
                    }
                }
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $layout  @return array<string, mixed>|null the node */
    public static function node(array $layout, string $nodeId): ?array
    {
        return Tree::find($layout, $nodeId);
    }

    /** @param  array<string, mixed>  $layout  @return array<string, mixed>|null the cell (with its `items`) */
    public static function cell(array $layout, string $cellId): ?array
    {
        foreach ($layout['rows'] ?? [] as $row) {
            foreach ($row['cells'] ?? [] as $cell) {
                if ($cell['id'] === $cellId) {
                    return $cell;
                }
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $layout  @return array<string, mixed>|null the row (with its `cells`) */
    public static function row(array $layout, string $rowId): ?array
    {
        foreach ($layout['rows'] ?? [] as $row) {
            if ($row['id'] === $rowId) {
                return $row;
            }
        }

        return null;
    }

    /** The id of the cell a node is in, or null. @param  array<string, mixed>  $layout */
    public static function cellOf(array $layout, string $nodeId): ?string
    {
        $at = self::position($layout, $nodeId);

        return $at === null ? null : $layout['rows'][$at['row']]['cells'][$at['cell']]['id'];
    }

    /**
     * Appends a node to the end of a cell.
     *
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException no such cell, or the node's id is already in the tree
     */
    public static function add(array $layout, string $cellId, array $node): array
    {
        return Tree::addNode($layout, $cellId, $node);
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException no such node
     */
    public static function remove(array $layout, string $nodeId): array
    {
        self::position($layout, $nodeId) ?? throw new InvalidArgumentException('There is no such node.');

        return Tree::removeNode($layout, $nodeId);
    }

    /**
     * The new settings of a node (the whole array replaces the old one).
     *
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException no such node
     */
    public static function setSettings(array $layout, string $nodeId, array $settings): array
    {
        $at = self::position($layout, $nodeId) ?? throw new InvalidArgumentException('There is no such node.');
        $layout['rows'][$at['row']]['cells'][$at['cell']]['items'][$at['index']]['settings'] = $settings;

        return $layout;
    }

    /**
     * The new props of a row (the whole array replaces the old one).
     *
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException no such row
     */
    public static function setRowProps(array $layout, string $rowId, array $props): array
    {
        foreach ($layout['rows'] ?? [] as $r => $row) {
            if ($row['id'] === $rowId) {
                $layout['rows'][$r]['props'] = $props;

                return $layout;
            }
        }

        throw new InvalidArgumentException('There is no such row.');
    }

    /**
     * The new props of a cell (the whole array replaces the old one).
     *
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException no such cell
     */
    public static function setCellProps(array $layout, string $cellId, array $props): array
    {
        foreach ($layout['rows'] ?? [] as $r => $row) {
            foreach ($row['cells'] as $c => $cell) {
                if ($cell['id'] === $cellId) {
                    $layout['rows'][$r]['cells'][$c]['props'] = $props;

                    return $layout;
                }
            }
        }

        throw new InvalidArgumentException('There is no such cell.');
    }

    /** May the node move that way? (the screen disables the button when not) @param  array<string, mixed>  $layout */
    public static function canMove(array $layout, string $nodeId, string $direction): bool
    {
        return self::target($layout, $nodeId, $direction) !== null;
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException no such node, an unknown direction, or a move that has nowhere to go
     */
    public static function move(array $layout, string $nodeId, string $direction): array
    {
        $target = self::target($layout, $nodeId, $direction) ?? throw new InvalidArgumentException('The node cannot move '.mb_substr($direction, 0, 10).'.');

        return Tree::moveNode($layout, $nodeId, $target['cell'], $target['position']);
    }

    /** @param  array<string, mixed>  $layout  @return array{cell: string, position: int}|null */
    private static function target(array $layout, string $nodeId, string $direction): ?array
    {
        $at = self::position($layout, $nodeId);

        if ($at === null || ! in_array($direction, self::DIRECTIONS, true)) {
            return null;
        }

        $row = $layout['rows'][$at['row']];
        $cell = $row['cells'][$at['cell']];

        if ($direction === 'up' || $direction === 'down') {
            $to = $at['index'] + ($direction === 'up' ? -1 : 1);

            return $to < 0 || $to >= count($cell['items']) ? null : ['cell' => $cell['id'], 'position' => $to];
        }

        // left / right stay inside the row
        $next = $row['cells'][$at['cell'] + ($direction === 'left' ? -1 : 1)] ?? null;

        return $next === null ? null : ['cell' => $next['id'], 'position' => count($next['items'])];
    }

    // ---- rows and cells (DS-2b: the footer's rows and columns) -------------------------------------------------------------------------------

    /**
     * Appends a row. `$max` is the most rows the zone allows (ZoneSpec): a DomainException `limit` when it is reached.
     *
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException an id that is already used
     * @throws \DomainException `limit`
     */
    public static function addRow(array $layout, array $row, ?int $max = null): array
    {
        if ($max !== null && count($layout['rows'] ?? []) >= $max) {
            throw new \DomainException('limit');
        }

        $used = Tree::ids($layout);

        foreach ([$row['id'], ...array_column($row['cells'] ?? [], 'id')] as $id) {
            in_array($id, $used, true) && throw new InvalidArgumentException('The id is already used.');
        }

        $layout['rows'][] = $row;

        return $layout;
    }

    /**
     * Removes a row. A row that still holds nodes is refused (`not_empty`) unless `$force` says the merchant confirmed.
     *
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException no such row
     * @throws \DomainException `not_empty`
     */
    public static function removeRow(array $layout, string $rowId, bool $force = false): array
    {
        $row = self::row($layout, $rowId) ?? throw new InvalidArgumentException('There is no such row.');

        if (! $force && array_sum(array_map(static fn (array $cell): int => count($cell['items']), $row['cells'])) > 0) {
            throw new \DomainException('not_empty');
        }

        $layout['rows'] = array_values(array_filter($layout['rows'], static fn (array $r): bool => $r['id'] !== $rowId));

        return $layout;
    }

    /**
     * Appends a cell to a row. `$max` is the most cells a row may hold: a DomainException `limit` when it is reached.
     *
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $cell
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException no such row, or an id already used
     * @throws \DomainException `limit`
     */
    public static function addCell(array $layout, string $rowId, array $cell, ?int $max = null): array
    {
        foreach ($layout['rows'] ?? [] as $r => $row) {
            if ($row['id'] !== $rowId) {
                continue;
            }

            if ($max !== null && count($row['cells']) >= $max) {
                throw new \DomainException('limit');
            }

            in_array($cell['id'], Tree::ids($layout), true) && throw new InvalidArgumentException('The id is already used.');
            $layout['rows'][$r]['cells'][] = $cell;

            return $layout;
        }

        throw new InvalidArgumentException('There is no such row.');
    }

    /**
     * Removes a cell. A cell that still holds nodes is refused (`not_empty`) unless `$force` says the merchant confirmed.
     *
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException no such cell
     * @throws \DomainException `not_empty`
     */
    public static function removeCell(array $layout, string $cellId, bool $force = false): array
    {
        $cell = self::cell($layout, $cellId) ?? throw new InvalidArgumentException('There is no such cell.');

        if (! $force && $cell['items'] !== []) {
            throw new \DomainException('not_empty');
        }

        foreach ($layout['rows'] as $r => $row) {
            $layout['rows'][$r]['cells'] = array_values(array_filter($row['cells'], static fn (array $c): bool => $c['id'] !== $cellId));
        }

        return $layout;
    }

    /** May a cell move one place `left` / `right` inside its row? @param  array<string, mixed>  $layout */
    public static function canMoveCell(array $layout, string $cellId, string $direction): bool
    {
        return self::cellTarget($layout, $cellId, $direction) !== null;
    }

    /**
     * Moves a cell one place inside its row (never to another row).
     *
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException no such cell, an unknown direction or no place to go
     */
    public static function moveCell(array $layout, string $cellId, string $direction): array
    {
        [$r, $from, $to] = self::cellTarget($layout, $cellId, $direction) ?? throw new InvalidArgumentException('The cell cannot move '.mb_substr($direction, 0, 10).'.');
        $cells = $layout['rows'][$r]['cells'];
        [$cells[$from], $cells[$to]] = [$cells[$to], $cells[$from]];
        $layout['rows'][$r]['cells'] = $cells;

        return $layout;
    }

    /** @param  array<string, mixed>  $layout  @return array{0: int, 1: int, 2: int}|null row index, the cell's index, the index it swaps with */
    private static function cellTarget(array $layout, string $cellId, string $direction): ?array
    {
        if (! in_array($direction, ['left', 'right'], true)) {
            return null;
        }

        foreach ($layout['rows'] ?? [] as $r => $row) {
            foreach ($row['cells'] as $c => $cell) {
                if ($cell['id'] === $cellId) {
                    $to = $c + ($direction === 'left' ? -1 : 1);

                    return $to < 0 || $to >= count($row['cells']) ? null : [$r, $c, $to];
                }
            }
        }

        return null;
    }
}
