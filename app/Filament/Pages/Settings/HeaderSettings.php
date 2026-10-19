<?php

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Support\HelpLink;
use App\Http\Controllers\Storefront\HeaderPreviewController;
use App\Services\ActivityLogger;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Header\HeaderCatalog;
use App\Storefront\Header\HeaderCompiler;
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
    use ServerSideValidation;
    use AuthorizesViaStaffPermission;

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
        return NavigationGroup::ADMIN;
    }

    public static function getNavigationSort(): ?int
    {
        return 36;
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
        $settings = app(SiteSettingsRepository::class);

        foreach ([AppearanceSettings::HEADER_DRAFT_KEY, AppearanceSettings::HEADER_PUBLISHED_KEY] as $key) {
            $json = $settings->get($key);
            $layout = is_string($json) ? json_decode($json, true) : null;

            if (is_array($layout)) {
                $validation = HeaderValidator::validate($layout);

                if ($validation->passes()) {
                    return $validation->layout;
                }
            }
        }

        return HeaderLayout::default();
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

        return $state;
    }

    /**
     * The submitted state as a layout, plus the map that turns a validator path back into the form field it belongs to.
     *
     * @param  array<string, mixed>  $state
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function fromForm(array $state): array
    {
        $map = [];
        $list = static function (mixed $items, string $path, string $field) use (&$map): array {
            $out = [];

            foreach (is_array($items) ? $items : [] as $key => $item) {
                $index = count($out);
                $map["{$path}.{$index}"] = "{$field}.{$key}.data";
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
                    $entry[$slot] = $list($data[$slot] ?? [], "{$path}.{$slot}", "data.{$path}.{$slot}");
                }

                $layout[$name]['rows'][$row] = $entry;
            }
        }

        $layout['mobile']['drawer'] = $list($state['mobile']['drawer'] ?? [], 'mobile.drawer', 'data.mobile.drawer');
        $layout['mobile']['drawer_side'] = (string) ($state['mobile']['drawer_side'] ?? 'left');

        return [$layout, $map];
    }

    /** Throws the validator's messages as the form's own field errors. @param  array<string, string>  $map */
    private function failWith(array $errors, array $map): never
    {
        $messages = [];

        foreach ($errors as $path => $message) {
            $field = null;

            // The longest known element prefix wins: "desktop.rows.main.left.0.settings.height" -> "data.desktop.rows.main.left.{key}.data.height".
            foreach ($map as $prefix => $target) {
                if (str_starts_with($path, $prefix.'.settings.') || $path === $prefix.'.type') {
                    $field = $target.'.'.(str_ends_with($path, '.type') ? '__type' : substr($path, strlen($prefix.'.settings.')));
                    $field = str_replace('.__type', '', $field);
                }
            }

            $messages[$field ?? 'data.'.preg_replace('/\.settings\..*$/', '', $path)] = $message;
        }

        $this->failFields($messages);
    }

    // ---- the form --------------------------------------------------------------------------------------------------------------------

    /** The Filament field for one setting of the catalog schema. @param  array<string, mixed>  $field */
    private static function field(string $name, array $field): Component
    {
        $label = __('header.settings.'.$name);

        return match ($field['kind']) {
            'int' => TextInput::make($name)->label($label)->numeric()->integer()->minValue((int) ($field['min'] ?? 0))->maxValue((int) ($field['max'] ?? 9999))->default($field['default'] ?? null),
            // A header made before the menus existed shows the plain category tree: say so, the merchant's own menus are one choice away.
            'menu_source' => Select::make($name)->label($label)->native(false)
                ->options(['categories' => __('header.options.categories')] + \App\Content\Menus\MenuModel::query()->orderBy('id')->pluck('name', 'key')->all())
                ->default($field['default'] ?? 'categories')
                ->live()
                ->hint(fn ($get): ?string => $get($name) === 'categories' ? __('header.warnings.categories_source') : null)
                ->hintColor('warning'),
            'bool' => Toggle::make($name)->label($label)->default((bool) ($field['default'] ?? false)),
            'enum' => Select::make($name)->label($label)->native(false)->options(array_combine($field['options'], array_map(static fn (string $o): string => __('header.options.'.$o), $field['options'])))->default($field['default'] ?? $field['options'][0]),
            'html' => Textarea::make($name)->label($label)->rows(4)->helperText(__('header.html_help'))->default(''),
            'social' => Repeater::make($name)->label($label)->maxItems((int) ($field['max'] ?? 8))->default([])
                ->schema([
                    Select::make('network')->label(__('header.settings.network'))->native(false)->options(array_combine(HeaderCatalog::SOCIAL_NETWORKS, array_map(static fn (string $n): string => __('header.social.'.$n), HeaderCatalog::SOCIAL_NETWORKS)))->required(),
                    TextInput::make('url')->label(__('header.settings.url'))->placeholder('https://')->required(),
                ])->columns(2),
            default => TextInput::make($name)->label($label)->default($field['default'] ?? '')->maxLength((int) ($field['max'] ?? 500)),
        };
    }

    /** One Builder (a slot or the drawer): a block per element type of the catalog. */
    private static function builder(string $name, string $label, string $layout, bool $drawer = false): Builder
    {
        $blocks = [];

        foreach (HeaderCatalog::all() as $type => $descriptor) {
            if ($drawer && ! in_array($type, HeaderCatalog::DRAWER_TYPES, true)) {
                continue;
            }

            $schema = $descriptor['schema'];

            if ($type === 'logo') {
                [$min, $max, $default] = HeaderLayout::LOGO_HEIGHTS[$layout];
                $schema['height'] = ['kind' => 'int', 'min' => $min, 'max' => $max, 'default' => $default];
            }

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
        [$layout, $map] = $this->fromForm($this->form->getState());
        $validation = HeaderValidator::validate($layout);

        if (! $validation->passes()) {
            $this->failWith($validation->errors, $map);
        }

        return $validation->layout;
    }

    public function saveDraft(): void
    {
        $layout = $this->validated();
        app(SiteSettingsRepository::class)->set(AppearanceSettings::HEADER_DRAFT_KEY, (string) json_encode($layout, JSON_THROW_ON_ERROR));
        $this->log('draft');

        Notification::make()->title(__('header.notices.draft_saved'))->success()->send();
    }

    public function preview(): void
    {
        $this->saveDraft();
        $this->js('window.open('.json_encode(HeaderPreviewController::previewUrl(), JSON_UNESCAPED_SLASHES).', "_blank")');
    }

    public function publish(): void
    {
        $layout = $this->validated();
        $settings = app(SiteSettingsRepository::class);
        $settings->set(AppearanceSettings::HEADER_DRAFT_KEY, (string) json_encode($layout, JSON_THROW_ON_ERROR));
        app(HeaderCompiler::class)->publish($layout, $settings);
        $this->log('published');

        Notification::make()->title(__('header.notices.published'))->success()->send();
    }

    /** «Върни публикуваното»: the draft becomes the published layout again (or the default when nothing was ever published). */
    public function discard(): void
    {
        $settings = app(SiteSettingsRepository::class);
        $settings->forget(AppearanceSettings::HEADER_DRAFT_KEY);
        $this->log('discarded');
        $this->form->fill($this->toForm($this->startingLayout()));

        Notification::make()->title(__('header.notices.discarded'))->success()->send();
    }

    /** One activity-log line: who (the logger knows) and which action; never a setting value. */
    private function log(string $action): void
    {
        app(ActivityLogger::class)->logFieldChanged('storefront_header', 'header', $action, null, 'saved');
    }
}
