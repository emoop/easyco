<?php

namespace App\Filament\Pages\Design;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\Design\FieldForms;
use App\Filament\NavigationGroup;
use App\Http\Controllers\Storefront\HeaderPreviewController;
use App\Storefront\Design\ComponentRegistry;
use App\Storefront\Design\Tree;
use App\Storefront\Design\TreeEditor;
use App\Storefront\Design\ZoneStore\HeaderAdapter;
use App\Storefront\Header\HeaderCatalog;
use App\Storefront\Header\HeaderDrafts;
use App\Storefront\Header\HeaderLayout;
use App\Storefront\Header\HeaderValidator;
use BackedEnum;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * «Дизайн» / "Design" (DS-2a, design-screen-design.md): one full-width screen for the storefront's layout. A zone list on the left, the palette of components on top, the SCHEMA of the
 * layout (rows -> cells -> component chips) with the preview frame below it, and the PROPERTIES of whatever is selected on the right. Zone now: Хедър (desktop and phone, the phone drawer
 * included); the other zones are listed and disabled. There is no drag and drop: a click on a palette chip adds the component to the selected cell, the arrows move it.
 *
 * The editing state is the generic TREE (HeaderAdapter::toTree of the draft, else the published layout, else the default) in a LOCKED property: the browser can only ask for actions, and every
 * action re-checks the permission and every id and type it is given. The tree is written back to the draft through HeaderAdapter::fromTree + HeaderValidator (the single authority) and
 * HeaderDrafts (the same code the old «Хедър» page saves with). Admin group «Дизайн», `content_manage`.
 */
class DesignScreen extends Page
{
    use AuthorizesViaStaffPermission;

    public const DRAWER_ROW = 'h-m-drawer-r';

    public const DRAWER_CELL = 'h-m-drawer';

    protected static ?string $slug = 'design';

    protected string $view = 'filament.pages.design.screen';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-swatch';

    /** The zone being edited (DS-2a: only the header). */
    #[Locked]
    public string $zone = 'header';

    /** `desktop` | `mobile` */
    #[Locked]
    public string $device = 'desktop';

    /** @var array{desktop?: array<string, mixed>, mobile?: array<string, mixed>} the two layout trees of the header */
    #[Locked]
    public array $tree = [];

    /** @var array{kind: string, id: string}|null `cell` | `node` | `row` | `layout` and its id (a layout's id is its device) */
    #[Locked]
    public ?array $selected = null;

    /** Has the tree changed since the draft was last saved from this screen? */
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

    // ---- the state -------------------------------------------------------------------------------------------------------------------

    public function mount(): void
    {
        $this->guard();
        $this->loadTree();
    }

    /** Livewire hydrates the properties after the page booted: the panel's form is built again for the CURRENT selection. */
    public function hydrate(): void
    {
        if ($this->tree !== []) {
            $this->cacheSchema('propsForm');
        }
    }

    /** The tree from storage (the same starting rule as the old Header page), nothing selected, nothing unsaved. */
    private function loadTree(): void
    {
        $this->tree = HeaderAdapter::toTree(HeaderDrafts::startingLayout());
        $this->selected = null;
        $this->dirty = false;
        $this->invalid = [];
        $this->previewUrl = HeaderPreviewController::previewUrl();
        $this->refreshProps();
    }

    /** @return array<string, mixed> */
    private function layoutTree(): array
    {
        return $this->tree[$this->device] ?? [];
    }

    // ---- what the view needs ---------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> the rows of the current device, the phone's drawer row left out */
    public function rows(): array
    {
        return array_values(array_filter($this->layoutTree()['rows'] ?? [], static fn (array $row): bool => $row['id'] !== self::DRAWER_ROW));
    }

    /** @return array<string, mixed>|null the phone's drawer cell */
    public function drawerCell(): ?array
    {
        return $this->device === 'mobile' ? TreeEditor::cell($this->layoutTree(), self::DRAWER_CELL) : null;
    }

    public function rowName(array $row): string
    {
        return (string) __('header.rows.'.substr($row['id'], strlen('h-'.($this->device === 'mobile' ? 'm' : 'd').'-')));
    }

    public function cellName(array $cell): string
    {
        return $cell['id'] === self::DRAWER_CELL ? (string) __('design.schema.drawer') : (string) __('header.slots.'.substr($cell['id'], strrpos($cell['id'], '-') + 1));
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
     * The palette: every component of the header's registry that the target cell may hold, with the REASON (a text) when it cannot be added now, or null.
     *
     * @return list<array{type: string, label: string, reason: ?string}>
     */
    public function palette(): array
    {
        $cell = $this->targetCell();
        $out = [];

        foreach (ComponentRegistry::for('header') as $type => $descriptor) {
            if ($cell === self::DRAWER_CELL && ! $this->drawerAllows($type)) {
                continue;
            }

            $out[] = ['type' => $type, 'label' => (string) ($descriptor['builtin'] ? __($descriptor['label']) : $descriptor['label']), 'reason' => $this->reason($type, $cell)];
        }

        return $out;
    }

    private function drawerAllows(string $type): bool
    {
        return in_array($type, HeaderCatalog::DRAWER_TYPES, true) && $type !== 'account';
    }

    /** Why a component cannot be added to that cell right now (null = it can). */
    private function reason(string $type, ?string $cellId): ?string
    {
        if ($cellId === null) {
            return (string) __('design.palette.no_cell');
        }

        $layout = $this->layoutTree();
        $cell = TreeEditor::cell($layout, $cellId);

        if ($cell === null || ! isset(ComponentRegistry::for('header')[$type])) {
            return (string) __('design.notices.unknown');
        }

        if ($cellId === self::DRAWER_CELL && ! $this->drawerAllows($type)) {
            return (string) __('design.palette.not_offered');
        }

        $max = $cellId === self::DRAWER_CELL ? HeaderLayout::MAX_DRAWER : HeaderLayout::MAX_PER_SLOT;

        if (count($cell['items']) >= $max) {
            return (string) __('design.palette.full', ['max' => $max]);
        }

        if (in_array($type, HeaderCatalog::ONCE_PER_LAYOUT, true) && $this->count($layout, $type) > 0) {
            return (string) __('design.palette.once');
        }

        return null;
    }

    /** @param  array<string, mixed>  $layout */
    private function count(array $layout, string $type): int
    {
        $n = 0;

        foreach ($layout['rows'] ?? [] as $row) {
            foreach ($row['cells'] as $cell) {
                foreach ($cell['items'] as $node) {
                    $n += $node['type'] === $type ? 1 : 0;
                }
            }
        }

        return $n;
    }

    /** The label of a node's type. */
    public function typeLabel(string $type): string
    {
        $descriptor = ComponentRegistry::for('header')[$type] ?? null;

        return $descriptor === null ? $type : (string) ($descriptor['builtin'] ? __($descriptor['label']) : $descriptor['label']);
    }

    /** A short summary of a node for its chip: the text / label / the choice it made. @param  array<string, mixed>  $node */
    public function summary(array $node): string
    {
        $settings = $node['settings'];

        foreach (['text', 'label'] as $key) {
            if (is_string($settings[$key] ?? null) && $settings[$key] !== '') {
                return mb_substr($settings[$key], 0, 24);
            }
        }

        return match ($node['type']) {
            'logo' => ((int) ($settings['height'] ?? 0)).'px',
            'social' => (string) count($settings['items'] ?? []),
            default => is_string($settings['style'] ?? null) ? (string) __('header.options.'.$settings['style']) : (is_string($settings['mode'] ?? null) ? (string) __('header.options.'.$settings['mode']) : ''),
        };
    }

    public function canMove(string $nodeId, string $direction): bool
    {
        return TreeEditor::canMove($this->layoutTree(), $nodeId, $direction);
    }

    public function isDirtyPreview(): bool
    {
        return $this->dirty;
    }

    // ---- selection --------------------------------------------------------------------------------------------------------------------

    public function setDevice(string $device): void
    {
        $this->guard();

        if (! in_array($device, ['desktop', 'mobile'], true)) {
            $this->refuse('unknown');

            return;
        }

        $this->commitProps();
        $this->device = $device;
        $this->select(null, null);
    }

    public function selectCell(string $id): void
    {
        $this->guard();
        TreeEditor::cell($this->layoutTree(), $id) === null ? $this->refuse('unknown') : $this->select('cell', $id);
    }

    public function selectNode(string $id): void
    {
        $this->guard();
        TreeEditor::node($this->layoutTree(), $id) === null ? $this->refuse('unknown') : $this->select('node', $id);
    }

    public function selectRow(string $id): void
    {
        $this->guard();
        $row = TreeEditor::row($this->layoutTree(), $id);
        $row === null || $id === self::DRAWER_ROW ? $this->refuse('unknown') : $this->select('row', $id);
    }

    public function selectLayout(): void
    {
        $this->guard();
        $this->select('layout', $this->device);
    }

    /** The panel's values go into the tree BEFORE the selection changes (nothing is lost), then the panel is rebuilt for the new thing. */
    private function select(?string $kind, ?string $id): void
    {
        $this->commitProps();
        $this->selected = $kind === null ? null : ['kind' => $kind, 'id' => (string) $id];
        $this->resetValidation();
        $this->refreshProps();
    }

    // ---- the tree actions --------------------------------------------------------------------------------------------------------------

    public function addNode(string $type): void
    {
        $this->guard();
        $this->commitProps();
        $cell = $this->targetCell();

        if (! isset(ComponentRegistry::for('header')[$type]) || $cell === null || $this->reason($type, $cell) !== null) {
            $this->refuse($cell === null ? 'unknown' : null, $cell === null ? null : $this->reason($type, $cell));

            return;
        }

        $settings = HeaderCatalog::defaultsFor($type);

        if ($type === 'logo') {
            $settings['height'] = HeaderLayout::LOGO_HEIGHTS[$this->device][2];
        }

        $id = 'n'.Str::lower(Str::random(8));

        if ($this->commitStructure(TreeEditor::add($this->layoutTree(), $cell, Tree::node($id, $type, $settings)))) {
            $this->select('node', $id);
        }
    }

    public function moveNode(string $id, string $direction): void
    {
        $this->guard();
        $this->commitProps();

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

    /** Takes a changed layout of the current device when the whole header still keeps the STRUCTURE rules; refuses it with the rule's message when not. @param array<string, mixed> $layout */
    private function commitStructure(array $layout): bool
    {
        $tree = $this->tree;
        $tree[$this->device] = $layout;
        $problem = $this->structureProblem($tree);

        if ($problem !== null) {
            $this->refuse(null, $problem);

            return false;
        }

        $this->tree = $tree;
        $this->dirty = true;

        return true;
    }

    /** The message of the first STRUCTURE rule the tree breaks (a limit, one logo, one menu button, a drawer type), or null. Setting errors wait for the save. @param array<string, mixed> $tree */
    private function structureProblem(array $tree): ?string
    {
        foreach (HeaderValidator::validate(HeaderAdapter::fromTree($tree))->errors as $path => $message) {
            if (preg_match('/^(?:desktop|mobile)\.(?:rows|drawer|rows\.[a-z]+\.(?:left|center|right))$/', $path) === 1 || preg_match('/^mobile\.drawer\.\d+$/', $path) === 1) {
                return $message;
            }
        }

        return null;
    }

    private function refuse(?string $key, ?string $message = null): void
    {
        Notification::make()->warning()->title(__('design.notices.refused_title'))->body($message ?? __('design.notices.'.($key ?? 'unknown')))->send();
    }

    // ---- the properties panel ----------------------------------------------------------------------------------------------------------

    public function propsForm(Schema $schema): Schema
    {
        return $schema->components($this->propsComponents())->statePath('props');
    }

    /** @return list<Component> */
    private function propsComponents(): array
    {
        $sel = $this->selected;
        $layout = $this->layoutTree();

        if ($sel === null || $layout === []) {
            return [Text::make(__('design.props.nothing'))];
        }

        if ($sel['kind'] === 'node') {
            $node = TreeEditor::node($layout, $sel['id']);
            $fields = $node === null ? [] : $this->fieldsOf($node['type']);

            return [Section::make($node === null ? '' : $this->typeLabel($node['type']))->schema([
                Group::make(array_map(static fn (string $name, array $field): Component => FieldForms::make($name, $field), array_keys($fields), array_values($fields)))->statePath('settings'),
            ])];
        }

        if ($sel['kind'] === 'row') {
            [$min, $max] = HeaderLayout::ROW_HEIGHTS[$this->device][substr($sel['id'], strlen('h-m-'))] ?? [0, 0];

            return [
                Section::make(__('design.props.general'))->schema([
                    Toggle::make('enabled')->label(__('header.fields.enabled')),
                    TextInput::make('height')->label(__('header.fields.height'))->helperText(__('header.fields.height_hint', ['min' => $min, 'max' => $max]))->numeric()->integer()->minValue($min)->maxValue($max)->suffix('px'),
                ]),
                Section::make(__('design.props.colors'))->schema([
                    ColorPicker::make('colors.bg')->label(__('design.props.bg')),
                    ColorPicker::make('colors.fg')->label(__('design.props.fg')),
                    Select::make('text_size')->label(__('design.props.text_size'))->native(false)->placeholder(__('design.props.text_size_default'))
                        ->options(array_combine(HeaderLayout::TEXT_SIZES, array_map(static fn (string $s): string => (string) __('design.props.sizes.'.$s), HeaderLayout::TEXT_SIZES))),
                ])->columns(2),
            ];
        }

        if ($sel['kind'] === 'layout') {
            $components = [Toggle::make('sticky')->label(__('header.fields.sticky'))];

            if ($this->device === 'mobile') {
                $components[] = Select::make('drawer_side')->label(__('header.fields.drawer_side'))->native(false)->options(['left' => __('header.options.left'), 'right' => __('header.options.right')]);
                $components[] = Toggle::make('drawer_upper')->label(__('header.fields.drawer_upper'));
            }

            return [Section::make(__('design.props.layout', ['device' => __('design.devices.'.$this->device)]))->schema($components)];
        }

        $cell = TreeEditor::cell($layout, $sel['id']);

        return [Text::make(__('design.props.cell', ['name' => $cell === null ? '' : $this->cellName($cell)]))];
    }

    /** @return array<string, array<string, mixed>> the fields of a node type (the logo's height range follows the device) */
    private function fieldsOf(string $type): array
    {
        $fields = ComponentRegistry::for('header')[$type]['fields'] ?? [];

        if ($type === 'logo') {
            [$min, $max, $default] = HeaderLayout::LOGO_HEIGHTS[$this->device];
            $fields['height'] = ['kind' => 'int', 'min' => $min, 'max' => $max, 'default' => $default];
        }

        return $fields;
    }

    /** The panel is rebuilt for the current selection and filled from the tree. */
    private function refreshProps(): void
    {
        $this->cacheSchema('propsForm');
        $this->getSchema('propsForm')?->fill($this->propsState());
    }

    /** @return array<string, mixed> */
    private function propsState(): array
    {
        $sel = $this->selected;
        $layout = $this->layoutTree();

        if ($sel === null) {
            return [];
        }

        if ($sel['kind'] === 'node') {
            $node = TreeEditor::node($layout, $sel['id']);

            return $node === null ? [] : ['settings' => $node['settings']];
        }

        if ($sel['kind'] === 'row') {
            $props = TreeEditor::row($layout, $sel['id'])['props'] ?? [];

            return [
                'enabled' => (bool) ($props['enabled'] ?? false), 'height' => $props['height'] ?? null,
                'colors' => ['bg' => $props['colors']['bg'] ?? null, 'fg' => $props['colors']['fg'] ?? null], 'text_size' => $props['text_size'] ?? null,
            ];
        }

        if ($sel['kind'] === 'layout') {
            $props = $layout['props'] ?? [];

            return ['sticky' => (bool) ($props['sticky'] ?? false)] + ($this->device === 'mobile' ? ['drawer_side' => $props['drawer_side'] ?? 'left', 'drawer_upper' => (bool) ($props['drawer_upper'] ?? true)] : []);
        }

        return [];
    }

    /** The panel's values are written into the tree (never validated here: the save does that). Nothing changes when they equal what the tree has. */
    private function commitProps(): void
    {
        $sel = $this->selected;
        $raw = is_array($this->props) ? $this->props : [];
        $layout = $this->layoutTree();

        if ($sel === null || $layout === []) {
            return;
        }

        $before = $layout;

        if ($sel['kind'] === 'node' && ($node = TreeEditor::node($layout, $sel['id'])) !== null) {
            $layout = TreeEditor::setSettings($layout, $sel['id'], $this->settingsFrom($this->fieldsOf($node['type']), is_array($raw['settings'] ?? null) ? $raw['settings'] : []));
        } elseif ($sel['kind'] === 'row' && TreeEditor::row($layout, $sel['id']) !== null && $sel['id'] !== self::DRAWER_ROW) {
            $props = ['enabled' => (bool) ($raw['enabled'] ?? false), 'height' => $this->wholeNumber($raw['height'] ?? null)];
            $colors = array_filter(['bg' => $raw['colors']['bg'] ?? null, 'fg' => $raw['colors']['fg'] ?? null], static fn (mixed $c): bool => is_string($c) && $c !== '');

            if ($colors !== []) {
                $props['colors'] = $colors;
            }

            if (is_string($raw['text_size'] ?? null) && $raw['text_size'] !== '') {
                $props['text_size'] = $raw['text_size'];
            }

            $layout = TreeEditor::setRowProps($layout, $sel['id'], $props);
        } elseif ($sel['kind'] === 'layout') {
            $layout['props']['sticky'] = (bool) ($raw['sticky'] ?? false);

            if ($this->device === 'mobile') {
                $layout['props']['drawer_side'] = is_string($raw['drawer_side'] ?? null) ? $raw['drawer_side'] : 'left';
                $layout['props']['drawer_upper'] = (bool) ($raw['drawer_upper'] ?? true);
            }
        }

        if ($layout !== $before) {
            $this->tree[$this->device] = $layout;
            $this->dirty = true;
        }
    }

    /**
     * The settings of a node from the panel: only the fields of its type, with the kinds the panel hands over in another shape put back (numbers, switches, the social list).
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function settingsFrom(array $fields, array $raw): array
    {
        $out = [];

        foreach ($fields as $name => $field) {
            $value = $raw[$name] ?? null;
            $out[$name] = match ($field['kind']) {
                'bool' => (bool) $value,
                'int' => $this->wholeNumber($value),
                'social' => array_values(array_map(static fn (mixed $item): array => ['network' => (string) ($item['network'] ?? ''), 'url' => (string) ($item['url'] ?? '')], array_filter(is_array($value) ? $value : [], 'is_array'))),
                default => $value === null ? '' : $value,
            };
        }

        return $out;
    }

    private function wholeNumber(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^\d{1,6}$/', $value) === 1 ? (int) $value : $value;
    }

    // ---- save, publish, discard --------------------------------------------------------------------------------------------------------

    /** «Приложи»: the panel goes into the tree, the tree is validated and saved as the draft, the preview is reloaded. */
    public function apply(): void
    {
        $this->guard();
        $layout = $this->validatedLayout();

        if ($layout === null) {
            return;
        }

        HeaderDrafts::saveDraft($layout);
        $this->afterSave();

        Notification::make()->title(__('design.notices.applied'))->success()->send();
    }

    public function publish(): void
    {
        $this->guard();
        $layout = $this->validatedLayout();

        if ($layout === null) {
            return;
        }

        HeaderDrafts::publish($layout);
        $this->afterSave();

        Notification::make()->title(__('design.notices.published'))->success()->send();
    }

    /** «Върни публикуваното»: the draft is forgotten and the tree is read again. */
    public function discard(): void
    {
        $this->guard();
        HeaderDrafts::discard();
        $this->loadTree();

        Notification::make()->title(__('design.notices.discarded'))->success()->send();
    }

    private function afterSave(): void
    {
        $this->dirty = false;
        $this->invalid = [];
        $this->previewUrl = HeaderPreviewController::previewUrl();
    }

    /**
     * The tree as a VALIDATED stored layout, or null when the validator refused it: then the first failing thing is selected, its messages stand under its fields, the others are marked in the
     * schema and a notification counts the errors. Nothing is written.
     *
     * @return array<string, mixed>|null
     */
    private function validatedLayout(): ?array
    {
        $this->resetValidation();
        $this->commitProps();
        $validation = HeaderValidator::validate(HeaderAdapter::fromTree($this->tree));

        if ($validation->passes()) {
            return $validation->layout;
        }

        $located = [];

        foreach ($validation->errors as $path => $message) {
            $located[] = ['message' => $message, 'at' => HeaderAdapter::locate($this->tree, $path)];
        }

        $this->invalid = array_values(array_unique(array_map(static fn (array $e): string => $e['at']['id'], array_filter($located, static fn (array $e): bool => $e['at'] !== null))));
        $first = array_values(array_filter($located, static fn (array $e): bool => $e['at'] !== null))[0]['at'] ?? null;

        if ($first !== null) {
            $variant = $first['kind'] === 'layout' ? $first['id'] : $this->variantOf($first['id']);

            if ($variant !== null && $variant !== $this->device) {
                $this->device = $variant;
            }

            $this->select($first['kind'], $first['id']);
        }

        $messages = [];

        foreach ($located as $e) {
            if ($first !== null && $e['at'] !== null && $e['at']['kind'] === $first['kind'] && $e['at']['id'] === $first['id'] && $e['at']['field'] !== null) {
                $messages['props.'.($first['kind'] === 'node' ? 'settings.' : '').$e['at']['field']] = $e['message'];
            }
        }

        Notification::make()->danger()->title(__('design.notices.errors', ['count' => count($validation->errors)]))->body(implode("\n", array_slice(array_values($validation->errors), 0, 3)))->send();

        if ($messages !== []) {
            $this->dispatch('form-validation-error', livewireId: $this->getId());

            throw ValidationException::withMessages($messages);
        }

        return null;
    }

    /** Which device's layout holds this row / cell / node id? @return 'desktop'|'mobile'|null */
    private function variantOf(string $id): ?string
    {
        foreach (['desktop', 'mobile'] as $variant) {
            if (in_array($id, Tree::ids($this->tree[$variant] ?? []), true)) {
                return $variant;
            }
        }

        return null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('to_admin')->label(__('design.back_to_admin'))->icon('heroicon-o-bars-3')->color('gray')->url('#')->extraAttributes(['x-on:click.prevent' => '$store.sidebar.open()']),
            Action::make('discard')->label(__('design.actions.discard'))->color('gray')->requiresConfirmation()->action(fn () => $this->discard()),
            Action::make('apply')->label(__('design.actions.apply'))->color('gray')->action(fn () => $this->apply()),
            Action::make('publish')->label(__('design.actions.publish'))->requiresConfirmation()->action(fn () => $this->publish()),
        ];
    }
}
