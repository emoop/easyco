<?php

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\Concerns\ReconcilesFormKeys;
use App\Filament\Design\FieldForms;
use App\Filament\NavigationGroup;
use App\Filament\Support\HelpLink;
use App\Http\Controllers\Storefront\HeaderPreviewController;
use App\Storefront\Header\HeaderCatalog;
use App\Storefront\Header\HeaderDrafts;
use App\Storefront\Header\HeaderLayout;
use App\Storefront\Header\HeaderValidator;
use BackedEnum;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/**
 * «Хедър» / "Header" (stage D2, storefront-customization-design.md 4.3): the header builder. Per layout (desktop, phone) and per row (top, main, bottom) an
 * enable switch, a HEIGHT and three ordered lists (left, centre, right) of elements from the catalog (no drawing canvas, C-D4); the phone tab also has the
 * drawer and its side. «Запази чернова» stores the draft, «Преглед» opens the home page with the DRAFT through a signed 30-minute url, «Публикувай» validates,
 * compiles and shows it to the public, «Върни публикуваното» throws the draft away. The form is built FROM THE CATALOG (an extension's element type is
 * edited like a built-in one) and HeaderValidator is the single authority: nothing the form accepts is trusted. Admin group, `content_manage`.
 */
class HeaderSettings extends Page
{
    use AuthorizesViaStaffPermission;
    use ReconcilesFormKeys;
    use ServerSideValidation;

    /** @var array<string, mixed> */
    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';

    public function getTitle(): string
    {
        return __('header.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('header.navigation_label');
    }

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::DESIGN;
    }

    public static function getNavigationSort(): ?int
    {
        return 20;
    }

    protected static function accessPermission(): ?Permission
    {
        return Permission::CONTENT_MANAGE;
    }

    public static function canAccess(): bool
    {
        return static::staffCanForAction(static::accessPermission());
    }

    // ---- the state -------------------------------------------------------------------------------------------------------------------

    /** The layout the form starts from: the draft, else the published one, else the seeded default (each re-validated; a bad stored one is skipped). */
    private function startingLayout(): array
    {
        return HeaderDrafts::startingLayout();
    }

    public function mount(): void
    {
        $this->form->fill($this->toForm($this->startingLayout()));
    }

    /** @param  array<string, mixed>  $layout */
    private function toForm(array $layout): array
    {
        $items = static fn (array $elements): array => array_map(static fn (array $element): array => ['type' => $element['type'], 'data' => $element['settings']], $elements);
        $state = [];

        foreach (['desktop', 'mobile'] as $name) {
            $state[$name] = ['sticky' => $layout[$name]['sticky'], 'rows' => []];

            foreach ($layout[$name]['rows'] as $row => $data) {
                $state[$name]['rows'][$row] = ['enabled' => $data['enabled'], 'height' => $data['height']]
                    + array_combine(HeaderLayout::SLOTS, array_map(static fn (string $slot): array => $items($data[$slot]), HeaderLayout::SLOTS));
            }
        }

        $state['mobile']['drawer'] = $items($layout['mobile']['drawer']);
        $state['mobile']['drawer_side'] = $layout['mobile']['drawer_side'];
        $state['mobile']['drawer_upper'] = $layout['mobile']['drawer_upper'];

        return $state;
    }

    /**
     * The submitted state as a layout, plus the map that turns a validator path back into the form field it belongs to.
     *
     * @param  array<string, mixed>  $state
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function fromForm(array $state, array $raw = []): array
    {
        $map = [];
        // getState() numbers the lists; the fields are named by the keys of the page's own state: the i-th item is the item with the i-th raw key (ReconcilesFormKeys)
        $list = function (mixed $items, string $path, string $field, string $rawPath) use (&$map, $raw): array {
            $out = [];
            $keys = $this->rawKeys($raw, $rawPath);

            foreach (is_array($items) ? array_values($items) : [] as $index => $item) {
                $key = $this->keyAt($keys, $index);
                $map["{$path}.{$index}"] = "{$field}.{$key}.data";

                // A list inside an element (the social networks, a repeater) is keyed by the repeater's own ids in the form too: the validator's i-th item is the i-th raw key.
                foreach ($this->rawKeys($raw, "{$rawPath}.{$key}.data.items") as $position => $itemKey) {
                    $map["{$path}.{$index}.settings.items.{$position}"] = "{$field}.{$key}.data.items.{$itemKey}";
                }
                $out[] = ['type' => (string) ($item['type'] ?? ''), 'settings' => is_array($item['data'] ?? null) ? $item['data'] : []];
            }

            return $out;
        };

        $layout = [];

        foreach (['desktop', 'mobile'] as $name) {
            $layout[$name] = ['sticky' => (bool) ($state[$name]['sticky'] ?? false), 'rows' => []];

            foreach (array_keys(HeaderLayout::ROW_HEIGHTS[$name]) as $row) {
                $data = $state[$name]['rows'][$row] ?? [];
                $path = "{$name}.rows.{$row}";
                $entry = ['enabled' => (bool) ($data['enabled'] ?? false), 'height' => $data['height'] ?? null];

                foreach (HeaderLayout::SLOTS as $slot) {
                    $entry[$slot] = $list($data[$slot] ?? [], "{$path}.{$slot}", "data.{$path}.{$slot}", "{$path}.{$slot}");
                }

                $layout[$name]['rows'][$row] = $entry;
            }
        }

        $layout['mobile']['drawer'] = $list($state['mobile']['drawer'] ?? [], 'mobile.drawer', 'data.mobile.drawer', 'mobile.drawer');
        $layout['mobile']['drawer_side'] = (string) ($state['mobile']['drawer_side'] ?? 'left');
        // HDR-3: the capitals switch is saved from the form (it was only validated and rendered, so every save wrote the default)
        $layout['mobile']['drawer_upper'] = (bool) ($state['mobile']['drawer_upper'] ?? true);

        return [$layout, $map];
    }

    /** Throws the validator's messages as the form's own field errors. @param  array<string, string>  $map */
    private function failWith(array $errors, array $map): never
    {
        uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $messages = [];

        foreach ($errors as $path => $message) {
            $field = null;

            // The longest known prefix wins: an element ("desktop.rows.main.left.1.settings.height" -> "data.desktop.rows.main.left.{key}.data.height") or one item of a list inside an element.
            foreach ($map as $prefix => $target) {
                if ($path !== $prefix && ! str_starts_with($path, $prefix.'.')) {
                    continue;
                }

                $rest = substr($path, strlen($prefix));

                if (str_ends_with($target, '.data')) {
                    $field = $rest === '.type' ? substr($target, 0, -strlen('.data')) : (str_starts_with($rest, '.settings.') ? $target.substr($rest, strlen('.settings')) : $target.$rest);
                } else {
                    $field = $target.$rest;
                }

                break;
            }

            $messages[$field ?? 'data.'.preg_replace('/\.settings\..*$/', '', $path)] = $message;
        }

        $this->failFields($messages);
    }

    // ---- the form --------------------------------------------------------------------------------------------------------------------

    /** The Filament field for one setting of the catalog schema (DS-2a: the mapping lives in FieldForms, shared with the Design screen). @param  array<string, mixed>  $field */
    private static function field(string $name, array $field): Component
    {
        return FieldForms::make($name, $field);
    }

    /** One Builder (a slot or the drawer): a block per element type of the catalog. */
    private static function builder(string $name, string $label, string $layout, bool $drawer = false): Builder
    {
        $blocks = [];

        foreach (HeaderCatalog::all() as $type => $descriptor) {
            // HDR-3: since MENU-4 the account rows of the drawer come from the routes, so `account` is no longer offered here (an old stored drawer list that holds it still validates)
            if ($drawer && (! in_array($type, HeaderCatalog::DRAWER_TYPES, true) || $type === 'account')) {
                continue;
            }

            $schema = $descriptor['schema'];

            if ($type === 'logo') {
                [$min, $max, $default] = HeaderLayout::LOGO_HEIGHTS[$layout];
                $schema['height'] = ['kind' => 'int', 'min' => $min, 'max' => $max, 'default' => $default];
            }

            // LG-1: the logo PICTURE is chosen in the «Дизайн» screen only; a save from this page keeps the stored one (HeaderDrafts::keepLook())
            $schema = array_filter($schema, static fn (array $definition): bool => ($definition['kind'] ?? null) !== 'media');

            $drawer && $type === 'menu' && $schema['menu']['place'] = 'drawer';
            $fields = array_map(static fn (string $field, array $definition): Component => static::field($field, $definition), array_keys($schema), array_values($schema));
            $blocks[] = Block::make($type)
                ->label($descriptor['builtin'] ? __($descriptor['label']) : $descriptor['label'])
                ->schema($fields)
                ->columns(2);
        }

        return Builder::make($name)
            ->label($label)
            ->blocks($blocks)
            ->maxItems($drawer ? HeaderLayout::MAX_DRAWER : HeaderLayout::MAX_PER_SLOT)
            ->collapsible()
            ->collapsed()
            ->blockNumbers(false)
            ->addActionLabel(__('header.add_element'))
            ->default([]);
    }

    private static function rowSection(string $layout, string $row): Section
    {
        [$min, $max] = HeaderLayout::ROW_HEIGHTS[$layout][$row];

        return Section::make(__('header.rows.'.$row))
            ->collapsible()
            ->schema([
                Toggle::make('enabled')->label(__('header.fields.enabled')),
                TextInput::make('height')->label(__('header.fields.height'))->helperText(__('header.fields.height_hint', ['min' => $min, 'max' => $max]))->numeric()->integer()->minValue($min)->maxValue($max)->suffix('px')->required(),
                static::builder('left', __('header.slots.left'), $layout),
                static::builder('center', __('header.slots.center'), $layout),
                static::builder('right', __('header.slots.right'), $layout),
            ])
            ->columns(2)
            ->statePath("{$layout}.rows.{$row}");
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                HelpLink::component('appearance-header', 'appearance'),
                Tabs::make('layouts')->tabs([
                    Tab::make(__('header.tabs.desktop'))->schema([
                        Toggle::make('desktop.sticky')->label(__('header.fields.sticky')),
                        static::rowSection('desktop', 'top'),
                        static::rowSection('desktop', 'main'),
                        static::rowSection('desktop', 'bottom'),
                    ]),
                    Tab::make(__('header.tabs.mobile'))->schema([
                        Toggle::make('mobile.sticky')->label(__('header.fields.sticky')),
                        static::rowSection('mobile', 'top'),
                        static::rowSection('mobile', 'main'),
                        Section::make(__('header.fields.drawer'))->collapsible()->schema([
                            Select::make('mobile.drawer_side')->label(__('header.fields.drawer_side'))->native(false)->options(['left' => __('header.options.left'), 'right' => __('header.options.right')]),
                            Toggle::make('mobile.drawer_upper')->label(__('header.fields.drawer_upper')),
                            static::builder('drawer', __('header.fields.drawer'), 'mobile', drawer: true)->statePath('mobile.drawer'),
                        ]),
                    ]),
                ]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            static::withoutNativeValidation(Form::make([EmbeddedSchema::make('form')]))->id('form')->livewireSubmitHandler('saveDraft'),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save_draft')->label(__('header.actions.save_draft'))->color('gray')->action(fn () => $this->saveDraft()),
            Action::make('preview')->label(__('header.actions.preview'))->color('gray')->icon('heroicon-o-eye')->action(fn () => $this->preview()),
            Action::make('discard')->label(__('header.actions.discard'))->color('gray')->requiresConfirmation()->action(fn () => $this->discard()),
            Action::make('publish')->label(__('header.actions.publish'))->requiresConfirmation()->action(fn () => $this->publish()),
        ];
    }

    // ---- the actions ------------------------------------------------------------------------------------------------------------------

    /** Validates the form's state; returns the NORMALISED layout or throws the field errors. */
    private function validated(): array
    {
        [$layout, $map] = $this->fromForm($this->form->getState(), $this->data ?? []);
        $layout = HeaderDrafts::keepLook($layout);
        $validation = HeaderValidator::validate($layout);

        if (! $validation->passes()) {
            $this->failWith($validation->errors, $map);
        }

        return $validation->layout;
    }

    public function saveDraft(): void
    {
        HeaderDrafts::saveDraft($this->validated());

        Notification::make()->title(__('header.notices.draft_saved'))->success()->send();
    }

    public function preview(): void
    {
        $this->saveDraft();
        $this->js('window.open('.json_encode(HeaderPreviewController::previewUrl(), JSON_UNESCAPED_SLASHES).', "_blank")');
    }

    public function publish(): void
    {
        HeaderDrafts::publish($this->validated());

        Notification::make()->title(__('header.notices.published'))->success()->send();
    }

    /** «Върни публикуваното»: the draft becomes the published layout again (or the default when nothing was ever published). */
    public function discard(): void
    {
        HeaderDrafts::discard();
        $this->form->fill($this->toForm($this->startingLayout()));

        Notification::make()->title(__('header.notices.discarded'))->success()->send();
    }
}
