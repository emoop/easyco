<?php

namespace App\Storefront\Design\ZoneStore;

use App\Storefront\Design\Tree;
use App\Storefront\Header\HeaderLayout;
use InvalidArgumentException;

/**
 * The header's STORED shape (D2: `header.layout.draft|published`) <-> the generic layout tree (DS-1). Pure functions: no setting is read or written here.
 *
 * Ids of stored data are DETERMINISTIC from the position, so the same stored JSON always gives the same tree:
 *   row `h-d-main`, cell `h-d-main-left`, node `h-d-main-left-0` (desktop; `h-m-...` for the phone), the phone drawer is the extra row `h-m-drawer-r` with the cell `h-m-drawer` and the nodes
 *   `h-m-drawer-0`, `h-m-drawer-1`, ...  (indexes start at 0).
 * Row props are what the stored row has (`enabled`, `height`); the layout props are `sticky` (and, on the phone, `drawer_side` and `drawer_upper`). Nothing is invented: `fromTree(toTree($x)) === $x`.
 *
 * @phpstan-type Stored array{desktop: array<string, mixed>, mobile: array<string, mixed>}
 */
final class HeaderAdapter
{
    private const SHORT = ['desktop' => 'd', 'mobile' => 'm'];

    private const DRAWER_ROW = 'h-m-drawer-r';

    private const DRAWER_CELL = 'h-m-drawer';

    /**
     * @param  array<string, mixed>  $stored  a VALIDATED header layout
     * @return array{desktop: array<string, mixed>, mobile: array<string, mixed>} the two layouts of the header, by variant
     */
    public static function toTree(array $stored): array
    {
        $out = [];

        foreach (self::SHORT as $variant => $short) {
            $layout = $stored[$variant];
            $props = ['sticky' => $layout['sticky']];
            $rows = [];

            foreach ($layout['rows'] as $name => $row) {
                $cells = [];

                foreach (HeaderLayout::SLOTS as $slot) {
                    $cell = "h-{$short}-{$name}-{$slot}";
                    $cells[] = Tree::cell($cell, [], self::nodes($row[$slot], $cell));
                }

                $rows[] = Tree::row("h-{$short}-{$name}", ['enabled' => $row['enabled'], 'height' => $row['height']], $cells);
            }

            if ($variant === 'mobile') {
                $props += ['drawer_side' => $layout['drawer_side'], 'drawer_upper' => $layout['drawer_upper']];
                $rows[] = Tree::row(self::DRAWER_ROW, [], [Tree::cell(self::DRAWER_CELL, [], self::nodes($layout['drawer'], self::DRAWER_CELL))]);
            }

            $out[$variant] = Tree::layout($props, $rows, $variant);
        }

        return $out;
    }

    /**
     * @param  array{desktop: array<string, mixed>, mobile: array<string, mixed>}  $tree
     * @return array<string, mixed> the stored shape (what HeaderValidator accepts when the tree holds valid settings)
     *
     * @throws InvalidArgumentException a tree that is not the header's
     */
    public static function fromTree(array $tree): array
    {
        $out = [];

        foreach (self::SHORT as $variant => $short) {
            $layout = $tree[$variant] ?? throw new InvalidArgumentException("The header tree has no {$variant} layout.");
            self::assertShape($layout);
            $rows = [];
            $drawer = [];

            foreach ($layout['rows'] as $row) {
                if ($variant === 'mobile' && $row['id'] === self::DRAWER_ROW) {
                    $drawer = self::elements($row['cells'][0]['items'] ?? []);

                    continue;
                }

                $name = substr($row['id'], strlen("h-{$short}-"));
                $entry = ['enabled' => $row['props']['enabled'], 'height' => $row['props']['height']];
                $bySlot = [];

                foreach ($row['cells'] as $cell) {
                    $bySlot[substr($cell['id'], strlen("h-{$short}-{$name}-"))] = $cell['items'];
                }

                foreach (HeaderLayout::SLOTS as $slot) {
                    $entry[$slot] = self::elements($bySlot[$slot] ?? []);
                }

                $rows[$name] = $entry;
            }

            $stored = ['sticky' => $layout['props']['sticky'], 'rows' => $rows];

            if ($variant === 'mobile') {
                $stored += ['drawer' => $drawer, 'drawer_side' => $layout['props']['drawer_side'], 'drawer_upper' => $layout['props']['drawer_upper']];
            }

            $out[$variant] = $stored;
        }

        return $out;
    }

    /** @param  list<array<string, mixed>>  $elements  @return list<array<string, mixed>> */
    private static function nodes(array $elements, string $cellId): array
    {
        $nodes = [];

        foreach (array_values($elements) as $i => $element) {
            $nodes[] = Tree::node("{$cellId}-{$i}", $element['type'], $element['settings']);
        }

        return $nodes;
    }

    /** @param  list<array<string, mixed>>  $nodes  @return list<array{type: string, settings: array<string, mixed>}> */
    private static function elements(array $nodes): array
    {
        return array_map(static fn (array $node): array => ['type' => $node['type'], 'settings' => $node['settings']], array_values($nodes));
    }

    /** @param  array<string, mixed>  $layout */
    private static function assertShape(array $layout): void
    {
        $problems = Tree::problems($layout);

        if ($problems !== [] || ! isset($layout['props']['sticky'])) {
            throw new InvalidArgumentException('The header tree is not valid: '.implode(', ', $problems));
        }
    }
}
