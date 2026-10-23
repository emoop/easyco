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
 * Row props are what the stored row has (`enabled`, `height`, and, when the row sets them, `colors {bg, fg}` and `text_size`: DS-2a); the layout props are `sticky` (and, on the phone, `drawer_side` and `drawer_upper`). Nothing is invented: `fromTree(toTree($x)) === $x`.
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

                $rows[] = Tree::row("h-{$short}-{$name}", self::rowProps($row), $cells);
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
                $entry = ['enabled' => $row['props']['enabled'], 'height' => $row['props']['height']] + self::look($row['props']);
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

    /**
     * Where a validator path (`desktop.rows.main.left.2.settings.url`) lives in the tree: the node, the cell, the row or the layout it belongs to, and the field of its props / settings
     * (`url`, `items.0.url`, `height`, `colors.bg`, `text_size`, `sticky` ...; null = the thing as a whole, e.g. a full cell). Null when the path points at nothing in this tree.
     *
     * @param  array{desktop: array<string, mixed>, mobile: array<string, mixed>}  $tree
     * @return array{kind: string, id: string, field: ?string}|null
     */
    public static function locate(array $tree, string $path): ?array
    {
        $parts = explode('.', $path);
        $variant = $parts[0];

        if (! isset(self::SHORT[$variant], $tree[$variant]) || count($parts) < 2) {
            return $variant === 'layout' ? ['kind' => 'layout', 'id' => 'desktop', 'field' => null] : null;
        }

        $short = self::SHORT[$variant];
        $layout = $tree[$variant];

        if ($parts[1] === 'drawer' && $variant === 'mobile') {
            return self::within($layout, self::DRAWER_CELL, array_slice($parts, 2));
        }

        if ($parts[1] === 'rows') {
            if (count($parts) < 3) {
                return ['kind' => 'layout', 'id' => $variant, 'field' => null];
            }

            $row = "h-{$short}-{$parts[2]}";

            if (count($parts) === 3 || ! in_array($parts[3], HeaderLayout::SLOTS, true)) {
                $field = count($parts) > 3 ? (in_array($parts[3], ['bg', 'fg'], true) ? 'colors.'.$parts[3] : $parts[3]) : null;

                return in_array($row, array_column($layout['rows'], 'id'), true) ? ['kind' => 'row', 'id' => $row, 'field' => $field] : null;
            }

            return self::within($layout, "{$row}-{$parts[3]}", array_slice($parts, 4));
        }

        return in_array($parts[1], ['sticky', 'drawer_side', 'drawer_upper'], true) ? ['kind' => 'layout', 'id' => $variant, 'field' => $parts[1]] : ['kind' => 'layout', 'id' => $variant, 'field' => null];
    }

    /** The part of a path after a cell: nothing = the cell, `N` = the N-th node, `N.settings.x` = a setting of it, `N.type` = its type. @param  list<string>  $rest */
    private static function within(array $layout, string $cellId, array $rest): ?array
    {
        foreach ($layout['rows'] as $row) {
            foreach ($row['cells'] as $cell) {
                if ($cell['id'] !== $cellId) {
                    continue;
                }

                if ($rest === []) {
                    return ['kind' => 'cell', 'id' => $cellId, 'field' => null];
                }

                $node = $cell['items'][(int) $rest[0]] ?? null;

                if (! ctype_digit($rest[0]) || $node === null) {
                    return ['kind' => 'cell', 'id' => $cellId, 'field' => null];
                }

                $field = count($rest) > 1 ? ($rest[1] === 'settings' ? implode('.', array_slice($rest, 2)) : $rest[1]) : null;

                return ['kind' => 'node', 'id' => $node['id'], 'field' => $field === '' ? null : $field];
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $row  a stored row @return array<string, mixed> */
    private static function rowProps(array $row): array
    {
        $props = ['enabled' => $row['enabled'], 'height' => $row['height']];
        $colors = array_filter(['bg' => $row['bg'] ?? null, 'fg' => $row['fg'] ?? null], static fn (mixed $c): bool => is_string($c));

        if ($colors !== []) {
            $props['colors'] = $colors;
        }

        if (isset($row['text_size'])) {
            $props['text_size'] = $row['text_size'];
        }

        return $props;
    }

    /** The optional look of a tree row as the stored keys, in the stored order. @param  array<string, mixed>  $props @return array<string, string> */
    private static function look(array $props): array
    {
        $out = [];

        foreach (['bg', 'fg'] as $key) {
            if (isset($props['colors'][$key]) && is_string($props['colors'][$key]) && $props['colors'][$key] !== '') {
                $out[$key] = $props['colors'][$key];
            }
        }

        if (isset($props['text_size']) && is_string($props['text_size']) && $props['text_size'] !== '') {
            $out['text_size'] = $props['text_size'];
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
