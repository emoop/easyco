<?php

namespace App\Filament\Pages\Design;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\Design\Zones\CheckoutHeaderZone;
use App\Filament\Design\Zones\DesignZone;
use App\Filament\Design\Zones\FooterZone;
use App\Filament\Design\Zones\HeaderZone;
use App\Filament\Design\Zones\PageCardsZone;
use App\Filament\Design\Zones\ProductPageZone;
use App\Filament\Design\Zones\RestructuresTree;
use App\Filament\Design\Zones\TopbarZone;
use App\Filament\NavigationGroup;
use App\Storefront\Design\Tree;
use App\Storefront\Design\TreeEditor;
use BackedEnum;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * «Дизайн» / "Design" (DS-2a, design-screen-design.md; zones since DS-2b): one full-width screen for the storefront's layout. A zone list on the left, the palette of components on top, the
 * SCHEMA of the zone (rows -> cells -> component chips) with the preview frame below it, and the PROPERTIES of whatever is selected on the right. There is no drag and drop: a click on a
 * palette chip adds the component to the selected cell, the arrows move it.
 *
 * The page keeps the Livewire state and the generic actions; everything specific to a zone (its tree, palette, rules, properties, validator and storage) is a DesignZone (app/Filament/Design/Zones).
 * Zones: Хедър, Футър (tree zones), Топбанер and «Количка и поръчка» (form zones: no tree, ONE properties form). The editing state is a LOCKED property: the browser can only ask for actions, and
 * every action re-checks the permission and every id and type it is given. Admin group «Дизайн», `content_manage`.
 */
class DesignScreen extends Page
{
    use AuthorizesViaStaffPermission;

    public const DRAWER_ROW = HeaderZone::DRAWER_ROW;

    public const DRAWER_CELL = HeaderZone::DRAWER_CELL;

    /** The zones that work, in the order of the list: key => class. */
    public const ZONES = ['topbar' => TopbarZone::class, 'header' => HeaderZone::class, 'footer' => FooterZone::class, 'checkout' => CheckoutHeaderZone::class, 'page' => PageCardsZone::class, 'product' => ProductPageZone::class];

    protected static ?string $slug = 'design';

    protected string $view = 'filament.pages.design.screen';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-swatch';

    /** The zone being edited: a key of ZONES. */
    #[Locked]
    public string $zone = 'header';

    /** The layout being edited: `desktop` | `mobile` (header), `upper` | `lower` (footer). */
    #[Locked]
    public string $device = 'desktop';

    /** @var array<string, mixed> the zone's state: the layout trees by layout key, or `{values}` of a form zone */
    #[Locked]
    public array $tree = [];

    /** @var array{kind: string, id: string}|null `cell` | `node` | `row` | `layout` | `form` and its id (a layout's id is its key) */
    #[Locked]
    public ?array $selected = null;

    /** Has the tree changed since it was last saved from this screen? */
    #[Locked]
    public bool $dirty = false;

    #[Locked]
    public string $previewUrl = '';

    /** @var list<string> ids of the things the last save refused (marked in the schema) */
    #[Locked]
    public array $invalid = [];

    /** The properties panel's form state. @var array<string, mixed> */
    public ?array $props = [];

    public function getTitle(): string
    {
        return __('design.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('design.navigation_label');
    }

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::DESIGN;
    }

    /** First in the group «Дизайн» (the others start at 10). */
    public static function getNavigationSort(): ?int
    {
        return 5;
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected static function accessPermission(): ?Permission
    {
        return Permission::CONTENT_MANAGE;
    }

    public static function canAccess(): bool
    {
        return static::staffCanForAction(static::accessPermission());
    }

    /** Livewire actions can be called directly: every one of them re-checks. */
    private function guard(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** The zone being edited (zones are stateless: a new object is as good as a kept one). */
    public function zoneObject(): DesignZone
    {
        $class = self::ZONES[$this->zone] ?? HeaderZone::class;

        return new $class;
    }

    // ---- the state -------------------------------------------------------------------------------------------------------------------

    public function mount(): void
    {
        $this->guard();

        // DS-3: the old «Хедър» / «Футър» urls land here with `?zone=` (a key of ZONES; anything else is ignored: the first zone)
        $zone = request()->query('zone');
        is_string($zone) && array_key_exists($zone, self::ZONES) && $this->zone = $zone;

        $this->loadTree();
    }

    /** Livewire hydrates the properties after the page booted: the panel's form is built again for the CURRENT selection. */
    public function hydrate(): void
    {
        if ($this->tree !== []) {
            $this->cacheSchema('propsForm');
        }
    }

    /** The zone's state from storage (the same starting rule as the old pages), nothing unsaved. */
    private function loadTree(): void
    {
        $zone = $this->zoneObject();
        $this->tree = $zone->load();
        $layouts = $zone->layouts();
        $this->device = in_array($this->device, $layouts, true) ? $this->device : ($layouts[0] ?? 'desktop');
        $this->selected = $zone->mode() === DesignZone::FORM ? ['kind' => 'form', 'id' => $zone->key()] : null;
        $this->dirty = false;
        $this->invalid = [];
        $this->previewUrl = $zone->previewUrl();
        $this->refreshProps();
    }

    /** @return array<string, mixed> */
    private function layoutTree(): array
    {
        return $this->tree[$this->device] ?? [];
    }

    public function isForm(): bool
    {
        return $this->zoneObject()->mode() === DesignZone::FORM;
    }

    // ---- what the view needs ---------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> the rows of the current layout (the phone's drawer row left out) */
    public function rows(): array
    {
        return $this->zoneObject()->rows($this->tree, $this->device);
    }

    /** @return array<string, mixed>|null the phone's drawer cell */
    public function drawerCell(): ?array
    {
        $zone = $this->zoneObject();

        return $zone instanceof HeaderZone ? $zone->drawerCell($this->tree, $this->device) : null;
    }

    public function rowName(array $row): string
    {
        return $this->zoneObject()->rowName($row, $this->device);
    }

    public function cellName(array $cell): string
    {
        return $this->zoneObject()->cellName($cell, $this->device);
    }

    /** The cell new components go to: the selected cell, or the cell of the selected component. */
    public function targetCell(): ?string
    {
        $sel = $this->selected;

        if ($sel === null) {
            return null;
        }

        return match ($sel['kind']) {
            'cell' => TreeEditor::cell($this->layoutTree(), $sel['id']) !== null ? $sel['id'] : null,
            'node' => TreeEditor::cellOf($this->layoutTree(), $sel['id']),
            default => null,
        };
    }

    /**
     * The palette: every component of the zone that the target cell may hold, with the REASON (a text) when it cannot be added now, or null.
     *
     * @return list<array{type: string, label: string, reason: ?string, hint: ?string}>
     */
    public function palette(): array
    {
        return $this->zoneObject()->palette($this->tree, $this->device, $this->targetCell());
    }

    /** The label of a node's type. */
    public function typeLabel(string $type): string
    {
        return $this->zoneObject()->typeLabel($type);
    }

    /** @param  array<string, mixed>  $node */
    public function summary(array $node): string
    {
        return $this->zoneObject()->summary($node);
    }

    public function canMove(string $nodeId, string $direction): bool
    {
        return TreeEditor::canMove($this->layoutTree(), $nodeId, $direction);
    }

    /** May a column (a cell of a row) move that way? */
    public function canMoveColumn(string $cellId, string $direction): bool
    {
        return TreeEditor::canMoveCell($this->layoutTree(), $cellId, $direction);
    }

    public function isDirtyPreview(): bool
    {
        return $this->dirty;
    }

    // ---- selection --------------------------------------------------------------------------------------------------------------------

    public function selectZone(string $zone): void
    {
        $this->guard();

        if (! array_key_exists($zone, self::ZONES)) {
            $this->refuse('unknown');

            return;
        }

        if ($zone === $this->zone) {
            return;
        }

        $this->commitProps();
        $this->zone = $zone;
        $this->device = 'desktop';
        $this->tree = [];
        $this->loadTree();
    }

    public function setDevice(string $device): void
    {
        $this->guard();

        if (! $this->zoneObject()->hasDevices() || ! in_array($device, $this->zoneObject()->layouts(), true)) {
            $this->refuse('unknown');

            return;
        }

        $this->commitProps();
        // the selection belongs to the layout it was made in: it is dropped BEFORE the layout changes, so the next commit cannot write its values into the other layout
        $this->selected = null;
        $this->device = $device;
        $this->select(null, null);
    }

    /** A zone without a device switch (the footer) has several layouts on screen at once: the layout an id belongs to becomes the current one. */
    private function focus(string $id): bool
    {
        $zone = $this->zoneObject();

        if ($zone->hasDevices() || $zone->mode() === DesignZone::FORM) {
            return true;
        }

        $layout = $this->layoutOf($id);

        if ($layout === null) {
            return false;
        }

        if ($layout !== $this->device) {
            $this->commitProps();
            $this->selected = null;
            $this->device = $layout;
        }

        return true;
    }

    public function selectCell(string $id): void
    {
        $this->guard();
        $this->focus($id) && TreeEditor::cell($this->layoutTree(), $id) !== null ? $this->select('cell', $id) : $this->refuse('unknown');
    }

    public function selectNode(string $id): void
    {
        $this->guard();
        $this->focus($id) && TreeEditor::node($this->layoutTree(), $id) !== null ? $this->select('node', $id) : $this->refuse('unknown');
    }

    public function selectRow(string $id): void
    {
        $this->guard();

        if (! $this->focus($id) || TreeEditor::row($this->layoutTree(), $id) === null || $id === self::DRAWER_ROW) {
            $this->refuse('unknown');

            return;
        }

        $this->select('row', $id);
    }

    public function selectLayout(?string $layout = null): void
    {
        $this->guard();
        $zone = $this->zoneObject();

        if ($zone->mode() === DesignZone::FORM) {
            return;
        }

        if ($layout !== null && ! $zone->hasDevices()) {
            if (! in_array($layout, $zone->layouts(), true)) {
                $this->refuse('unknown');

                return;
            }

            if ($layout !== $this->device) {
                $this->commitProps();
                $this->selected = null;
                $this->device = $layout;
            }
        }

        $this->select('layout', $this->device);
    }

    /** The panel's values go into the tree BEFORE the selection changes (nothing is lost), then the panel is rebuilt for the new thing. */
    private function select(?string $kind, ?string $id): void
    {
        $this->commitProps();
        $this->selected = $kind === null ? ($this->isForm() ? ['kind' => 'form', 'id' => $this->zone] : null) : ['kind' => $kind, 'id' => (string) $id];
        $this->resetValidation();
        $this->refreshProps();
    }

    // ---- the tree actions --------------------------------------------------------------------------------------------------------------

    public function addNode(string $type): void
    {
        $this->guard();
        $this->commitProps();
        $cell = $this->targetCell();

        if ($cell === null) {
            $this->refuse('unknown');

            return;
        }

        $entry = collect($this->palette())->firstWhere('type', $type);

        if ($entry === null) {
            $this->refuse('unknown');

            return;
        }

        if ($entry['reason'] !== null) {
            $this->refuse(null, $entry['reason']);

            return;
        }

        $id = 'n'.Str::lower(Str::random(8));

        if ($this->commitStructure(TreeEditor::add($this->layoutTree(), $cell, $this->zoneObject()->newNode($type, $this->device, $id)))) {
            $this->select('node', $id);
        }
    }

    public function moveNode(string $id, string $direction): void
    {
        $this->guard();
        $this->commitProps();
        $this->focus($id);

        if (! TreeEditor::canMove($this->layoutTree(), $id, $direction)) {
            $this->refuse(TreeEditor::node($this->layoutTree(), $id) === null ? 'unknown' : 'cannot_move');

            return;
        }

        $this->commitStructure(TreeEditor::move($this->layoutTree(), $id, $direction));
    }

    public function removeNode(string $id): void
    {
        $this->guard();
        $this->commitProps();
        $this->focus($id);
        $cell = TreeEditor::cellOf($this->layoutTree(), $id);

        if ($cell === null) {
            $this->refuse('unknown');

            return;
        }

        if ($this->commitStructure(TreeEditor::remove($this->layoutTree(), $id))) {
            $this->invalid = array_values(array_diff($this->invalid, [$id]));
            $this->selected = ['kind' => 'cell', 'id' => $cell];
            $this->refreshProps();
        }
    }

    /** «Премахни» of the LOGO (the layout needs it): asks first. The screen calls removeNode() directly for every other component, which goes at once. */
    public function removeNodeAction(): Action
    {
        return Action::make('removeNode')
            ->label(__('design.actions.remove'))
            ->requiresConfirmation()
            ->modalHeading(__('design.actions.remove_logo_title'))
            ->modalDescription(__('design.actions.remove_logo_body'))
            ->action(fn (array $arguments) => $this->removeNode((string) ($arguments['id'] ?? '')));
    }

    /** «Премахни колоната» that still holds blocks: asks first and names them. */
    public function removeColumnAction(): Action
    {
        return Action::make('removeColumn')
            ->label(__('design.actions.remove_column'))
            ->requiresConfirmation()
            ->modalHeading(__('design.actions.remove_column_title'))
            ->modalDescription(fn (array $arguments): string => __('design.actions.remove_column_body', ['blocks' => $this->blockNames((string) ($arguments['id'] ?? ''))]))
            ->action(fn (array $arguments) => $this->removeColumn((string) ($arguments['id'] ?? ''), true));
    }

    /** «Премахни реда» that still holds blocks: asks first and names them. */
    public function removeRowAction(): Action
    {
        return Action::make('removeRow')
            ->label(__('design.actions.remove_row'))
            ->requiresConfirmation()
            ->modalHeading(__('design.actions.remove_row_title'))
            ->modalDescription(fn (array $arguments): string => __('design.actions.remove_row_body', ['blocks' => $this->blockNames((string) ($arguments['id'] ?? ''))]))
            ->action(fn (array $arguments) => $this->removeRow((string) ($arguments['id'] ?? ''), true));
    }

    /** The labels of the blocks inside a column or a row, for the confirmation. */
    public function blockNames(string $id): string
    {
        $layout = $this->layoutTree();
        $zone = $this->zoneObject();
        $names = [];

        foreach ($layout['rows'] ?? [] as $row) {
            foreach ($row['cells'] as $cell) {
                if ($cell['id'] === $id || $row['id'] === $id) {
                    foreach ($cell['items'] as $node) {
                        $names[] = $zone->typeLabel($node['type']);
                    }
                }
            }
        }

        return implode(', ', $names);
    }

    // -- rows and columns (the footer) -----------------------------------------------------------------------------------------------------

    public function addRow(): void
    {
        $this->restructure('add_row', '');
    }

    public function removeRow(string $id, bool $confirmed = false): void
    {
        $this->restructure('remove_row', $id, $confirmed);
    }

    public function addColumn(string $rowId): void
    {
        $this->restructure('add_column', $rowId);
    }

    public function removeColumn(string $id, bool $confirmed = false): void
    {
        $this->restructure('remove_column', $id, $confirmed);
    }

    public function moveColumn(string $id, string $direction): void
    {
        in_array($direction, ['left', 'right'], true) ? $this->restructure('move_column_'.$direction, $id) : $this->refuse('cannot_move');
    }

    /** One of RestructuresTree::ACTIONS on the footer's upper level; a limit or an unconfirmed removal is a notice and nothing changes. */
    private function restructure(string $action, string $id, bool $confirmed = false): void
    {
        $this->guard();
        $this->commitProps();
        $zone = $this->zoneObject();

        if (! $zone instanceof RestructuresTree || ! in_array($action, RestructuresTree::ACTIONS, true) || ! preg_match('/^[a-z0-9-]{0,40}$/', $id)) {
            $this->refuse('unknown');

            return;
        }

        $this->focus($id === '' ? 'f-u-r0' : $id);

        try {
            $tree = $zone->restructure($this->tree, $action, $id, $confirmed);
        } catch (\DomainException $e) {
            $this->refuse(null, $e->getMessage());

            return;
        }

        $problem = $zone->structureProblem($tree);

        if ($problem !== null) {
            $this->refuse(null, $problem);

            return;
        }

        $this->tree = $tree;
        $this->dirty = true;
        $removed = $action === 'remove_column' || $action === 'remove_row';
        $this->invalid = [];

        if ($removed || $this->selected !== null && TreeEditor::cell($this->layoutTree(), $this->selected['id']) === null && TreeEditor::node($this->layoutTree(), $this->selected['id']) === null && TreeEditor::row($this->layoutTree(), $this->selected['id']) === null && $this->selected['kind'] !== 'layout') {
            $this->selected = null;
        }

        $this->refreshProps();
    }

    /** Takes a changed layout of the current key when the whole zone still keeps the STRUCTURE rules; refuses it with the rule's message when not. @param array<string, mixed> $layout */
    private function commitStructure(array $layout): bool
    {
        $tree = $this->tree;
        $tree[$this->device] = $layout;
        $problem = $this->zoneObject()->structureProblem($tree);

        if ($problem !== null) {
            $this->refuse(null, $problem);

            return false;
        }

        $this->tree = $tree;
        $this->dirty = true;

        return true;
    }

    private function refuse(?string $key, ?string $message = null): void
    {
        Notification::make()->warning()->title(__('design.notices.refused_title'))->body($message ?? __('design.notices.'.($key ?? 'unknown')))->send();
    }

    // ---- the properties panel ----------------------------------------------------------------------------------------------------------

    public function propsForm(Schema $schema): Schema
    {
        return $schema->components($this->zoneObject()->propsComponents($this->tree, $this->device, $this->selected))->statePath('props');
    }

    /** The panel is rebuilt for the current selection and filled from the tree. */
    private function refreshProps(): void
    {
        $this->cacheSchema('propsForm');
        $this->getSchema('propsForm')?->fill($this->zoneObject()->propsState($this->tree, $this->device, $this->selected));
    }

    /** The panel's values are written into the tree (never validated here: the save does that). Nothing changes when they equal what the tree has. */
    private function commitProps(): void
    {
        if ($this->selected === null || $this->tree === []) {
            return;
        }

        $tree = $this->zoneObject()->applyProps($this->tree, $this->device, $this->selected, is_array($this->props) ? $this->props : []);

        if ($tree !== $this->tree) {
            $this->tree = $tree;
            $this->dirty = true;
        }
    }

    // ---- save, publish, discard --------------------------------------------------------------------------------------------------------

    /** «Приложи» / «Запази»: the panel goes into the tree, the tree is validated and saved (a draft where the zone has one), the preview is reloaded. */
    public function apply(): void
    {
        $this->guard();
        $stored = $this->validatedStored();

        if ($stored === null) {
            return;
        }

        $zone = $this->zoneObject();
        $zone->save($stored);
        $this->afterSave();

        Notification::make()->title($zone->hasDraft() ? __('design.notices.applied') : __('design.notices.saved'))->success()->send();
        $this->warn($zone, $stored);
    }

    public function publish(): void
    {
        $this->guard();
        $zone = $this->zoneObject();

        if (! $zone->hasDraft()) {
            $this->refuse('unknown');

            return;
        }

        $stored = $this->validatedStored();

        if ($stored === null) {
            return;
        }

        try {
            $zone->publish($stored);
        } catch (\InvalidArgumentException) {
            $this->refuse(null, (string) __('design.notices.too_large'));

            return;
        }

        $this->afterSave();

        Notification::make()->title(__('design.notices.published_'.$this->zone))->success()->send();
        $this->warn($zone, $stored);
    }

    /** «Върни публикуваното»: the draft is forgotten and the tree is read again. */
    public function discard(): void
    {
        $this->guard();
        $zone = $this->zoneObject();

        if (! $zone->hasDraft()) {
            $this->refuse('unknown');

            return;
        }

        $zone->discard();
        $this->loadTree();

        Notification::make()->title(__('design.notices.discarded'))->success()->send();
    }

    /** «Върни по подразбиране» (the footer, the plain sections): the default goes into the TREE only; nothing is stored until «Приложи» / «Запази». */
    public function resetToDefault(): void
    {
        $this->guard();
        $zone = $this->zoneObject();

        if (! method_exists($zone, 'defaultTree')) {
            $this->refuse('unknown');

            return;
        }

        $this->tree = $zone->defaultTree();
        // a form zone's panel IS its selection: it stays selected (GL-2); a tree zone starts with nothing selected
        $this->selected = $zone->mode() === DesignZone::FORM ? ['kind' => 'form', 'id' => $zone->key()] : null;
        $this->device = $zone->layouts()[0] ?? $this->device;
        $this->invalid = [];
        $this->dirty = true;
        $this->refreshProps();

        Notification::make()->title(__($zone->mode() === DesignZone::FORM ? 'design.notices.defaulted_form' : 'design.notices.defaulted'))->success()->send();
    }

    public function hasDefault(): bool
    {
        return method_exists($this->zoneObject(), 'defaultTree');
    }

    private function afterSave(): void
    {
        $zone = $this->zoneObject();
        $this->dirty = false;
        $this->invalid = [];
        $this->previewUrl = $zone->previewUrl();
    }

    /** @param  array<string, mixed>  $stored */
    private function warn(DesignZone $zone, array $stored): void
    {
        foreach ($zone->warnings($stored) as $warning) {
            Notification::make()->warning()->title($warning)->send();
        }
    }

    /**
     * The tree as a VALIDATED stored value, or null when the validator refused it: then the first failing thing is selected, its messages stand under its fields, the others are marked in the
     * schema and a notification counts the errors. Nothing is written.
     *
     * @return array<string, mixed>|null
     */
    private function validatedStored(): ?array
    {
        $this->resetValidation();
        $this->commitProps();
        $zone = $this->zoneObject();
        [$stored, $errors] = $zone->validate($this->tree);

        if ($errors === []) {
            return $stored;
        }

        $located = [];

        foreach ($errors as $path => $message) {
            $located[] = ['message' => $message, 'at' => $zone->locate($this->tree, $path)];
        }

        $known = array_values(array_filter($located, static fn (array $e): bool => $e['at'] !== null));
        $this->invalid = array_values(array_unique(array_map(static fn (array $e): string => $e['at']['id'], $known)));
        $first = $known[0]['at'] ?? null;

        if ($first !== null) {
            if (! $zone->hasDevices() && $zone->mode() === DesignZone::TREE) {
                $layout = $first['kind'] === 'layout' ? $first['id'] : $this->layoutOf($first['id']);
                $layout !== null && $this->device = $layout;
            } elseif ($zone->hasDevices()) {
                $layout = $first['kind'] === 'layout' ? $first['id'] : $this->layoutOf($first['id']);
                $layout !== null && $layout !== $this->device && $this->device = $layout;
            }

            $this->select($first['kind'], $first['id']);
        }

        $messages = [];

        foreach ($known as $e) {
            $at = $e['at'];

            if ($first !== null && $at['kind'] === $first['kind'] && $at['id'] === $first['id'] && ($field = $zone->fieldPath($at)) !== null) {
                $messages['props.'.$field] = $e['message'];
            }
        }

        Notification::make()->danger()->title(__('design.notices.errors', ['count' => count($errors)]))->body(implode("\n", array_slice(array_values($errors), 0, 3)))->send();

        if ($messages !== []) {
            $this->dispatch('form-validation-error', livewireId: $this->getId());

            throw ValidationException::withMessages($messages);
        }

        return null;
    }

    /** Which layout key holds this row / cell / node id? */
    private function layoutOf(string $id): ?string
    {
        foreach ($this->zoneObject()->layouts() as $layout) {
            if (in_array($id, Tree::ids($this->tree[$layout] ?? []), true)) {
                return $layout;
            }
        }

        return null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('to_admin')->label(__('design.back_to_admin'))->icon('heroicon-o-bars-3')->color('gray')->url('#')->extraAttributes(['x-on:click.prevent' => '$store.sidebar.open()']),
            Action::make('default')->label(__('design.actions.default'))->color('gray')->requiresConfirmation()->visible(fn (): bool => $this->hasDefault())->action(fn () => $this->resetToDefault()),
            Action::make('discard')->label(__('design.actions.discard'))->color('gray')->requiresConfirmation()->visible(fn (): bool => $this->zoneObject()->hasDraft())->action(fn () => $this->discard()),
            Action::make('apply')->label(fn (): string => (string) __($this->zoneObject()->hasDraft() ? 'design.actions.apply' : 'design.actions.save'))->color('gray')->action(fn () => $this->apply()),
            Action::make('publish')->label(__('design.actions.publish'))->requiresConfirmation()->visible(fn (): bool => $this->zoneObject()->hasDraft())->action(fn () => $this->publish()),
        ];
    }
}
