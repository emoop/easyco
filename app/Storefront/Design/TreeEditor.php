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
}
