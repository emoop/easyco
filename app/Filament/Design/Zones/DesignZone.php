<?php

namespace App\Filament\Design\Zones;

use Filament\Schemas\Components\Component;

/**
 * ONE ZONE of the «Дизайн» screen (DS-2b): everything that is specific to the header, the footer, the top banner or the checkout header. The page (DesignScreen) keeps the Livewire state
 * (Locked `tree`, `selected`, `device`, `props`, the preview url), the permission checks and the generic selecting / adding / moving / removing; it asks the zone for the rest. A zone is
 * STATELESS: every method gets the tree (and the layout key) it works on and returns data; only load(), save(), publish() and discard() touch storage.
 *
 * Two kinds of zone: `tree` (header, footer: rows -> cells -> nodes, a palette, structure actions) and `form` (top banner, checkout header: no tree, ONE properties form; the page keeps its
 * values in `tree['values']` and the selection is always the form itself).
 *
 * Layout keys: the header has `desktop` / `mobile`, the footer `upper` / `lower`; a form zone has none. A selected thing is `{kind: cell|node|row|layout|form, id}`.
 */
interface DesignZone
{
    public const TREE = 'tree';

    public const FORM = 'form';

    public function key(): string;

    /** `tree` | `form` */
    public function mode(): string;

    /** @return list<string> the layout keys of the tree ([] for a form zone) */
    public function layouts(): array;

    /** Does the screen show the Десктоп / Телефон switch for this zone? */
    public function hasDevices(): bool;

    /** The blade partial that draws the schema (the boxes) of the zone. */
    public function schemaView(): string;

    public function previewTitle(): string;

    /** Does the zone keep a DRAFT (apply = draft, publish, discard)? A zone without one saves live («Запази») and has no publish / discard. */
    public function hasDraft(): bool;

    /** The state the page keeps in `tree`. @return array<string, mixed> */
    public function load(): array;

    /** A fresh url for the preview frame (a signed one for a draft). */
    public function previewUrl(): string;

    /** A CSS selector the preview frame scrolls to once it has loaded (the footer), or ''. The storefront's HTML is never changed for it. */
    public function previewScroll(): string;

    /** The rows the schema draws for a layout (the phone's drawer row is not one of them). @param  array<string, mixed>  $tree  @return list<array<string, mixed>> */
    public function rows(array $tree, string $layout): array;

    /** @param  array<string, mixed>  $row */
    public function rowName(array $row, string $layout): string;

    public function typeLabel(string $type): string;

    /** A short summary of a node for its chip. @param  array<string, mixed>  $node */
    public function summary(array $node): string;

    /** @param  array<string, mixed>  $cell */
    public function cellName(array $cell, string $layout): string;

    /**
     * The palette for a target cell: every type the zone offers with the REASON (a text) it cannot be added right now, or null.
     *
     * @param  array<string, mixed>  $tree
     * @return list<array{type: string, label: string, reason: ?string, hint: ?string}>
     */
    public function palette(array $tree, string $layout, ?string $cellId): array;

    /** A new node of a type, ready to go into a cell. @return array<string, mixed> */
    public function newNode(string $type, string $layout, string $id): array;

    /** The message of the first STRUCTURE rule the whole tree breaks (a limit, one logo ...), or null. @param  array<string, mixed>  $tree */
    public function structureProblem(array $tree): ?string;

    /**
     * The properties panel for the selected thing.
     *
     * @param  array<string, mixed>  $tree
     * @param  array{kind: string, id: string}|null  $selected
     * @return list<Component>
     */
    public function propsComponents(array $tree, string $layout, ?array $selected): array;

    /**
     * @param  array<string, mixed>  $tree
     * @param  array{kind: string, id: string}|null  $selected
     * @return array<string, mixed> the values the panel starts with
     */
    public function propsState(array $tree, string $layout, ?array $selected): array;

    /**
     * The panel's values written into the tree (never validated here). Returns the whole tree.
     *
     * @param  array<string, mixed>  $tree
     * @param  array{kind: string, id: string}|null  $selected
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function applyProps(array $tree, string $layout, ?array $selected, array $raw): array;

    /** @param  array<string, mixed>  $tree  @return array{0: array<string, mixed>, 1: array<string, string>} the validated stored value and the messages by path */
    public function validate(array $tree): array;

    /** Where a validator path lives in the tree: {kind, id, field}. @param  array<string, mixed>  $tree  @return array{kind: string, id: string, field: ?string}|null */
    public function locate(array $tree, string $path): ?array;

    /** The panel's field of a located error: `settings.url` for a node, `height` for a row ... (no `props.` prefix). @param  array{kind: string, id: string, field: ?string}  $at */
    public function fieldPath(array $at): ?string;

    /** Non-blocking notices after a save (the footer's low contrast). @param  array<string, mixed>  $stored  @return list<string> */
    public function warnings(array $stored): array;

    /** @param  array<string, mixed>  $stored a VALIDATED value */
    public function save(array $stored): void;

    /** @param  array<string, mixed>  $stored a VALIDATED value */
    public function publish(array $stored): void;

    public function discard(): void;
}
