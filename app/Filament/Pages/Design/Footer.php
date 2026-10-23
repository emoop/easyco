<?php

namespace App\Filament\Pages\Design;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\Menus\MenuModel;
use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\Concerns\ReconcilesFormKeys;
use App\Filament\NavigationGroup;
use App\Filament\Pages\Settings\ServerSideValidation;
use App\Filament\Support\HelpLink;
use App\Http\Controllers\Storefront\FooterPreviewController;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Footer\FooterCatalog;
use App\Storefront\Footer\FooterDrafts;
use App\Storefront\Footer\FooterLayout;
use App\Storefront\Footer\FooterValidator;
use App\Storefront\Header\HeaderCatalog;
use BackedEnum;
use Closure;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Route;

/**
 * «Футър» / "Footer" (FT-1, storefront-customization-design.md 18): the footer builder, built like the Header page (no drawing canvas): TWO sections, «Горно ниво» (padding, colours,
 * the border, then 1 to 2 rows, each of 1 to 5 columns with a width, an alignment and an ordered list of blocks from the catalog) and «Долно ниво» (padding, colours, alignment and
 * one line of text with the `[year]` token). «Запази чернова» stores the draft, «Преглед» opens the home page with the DRAFT through a signed 30-minute url, «Публикувай» validates, compiles
 * and shows it to the public, «Върни публикуваното» throws the draft away, «Върни по подразбиране» puts the seeded footer into the form. The form is built FROM THE CATALOG (an extension's
 * block is edited like a built-in one) and FooterValidator is the single authority: nothing the form accepts is trusted. Admin group «Дизайн», `content_manage`.
 */
class Footer extends Page
{
    use AuthorizesViaStaffPermission;
    use ReconcilesFormKeys;
    use ServerSideValidation;

    protected static ?string $slug = 'footer';

    /** @var array<string, mixed> */
    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bars-3-bottom-left';

    public function getTitle(): string
    {
        return __('footer.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('footer.navigation_label');
    }

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::DESIGN;
    }

    /** After «Хедър» (20), before «Менюта» (30). */
    public static function getNavigationSort(): ?int
    {
        return 25;
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
        return FooterDrafts::startingLayout();
    }

    public function mount(): void
    {
        $this->form->fill($this->toForm($this->startingLayout()));
    }

    /** @param  array<string, mixed>  $layout */
    private function toForm(array $layout): array
    {
        $rows = [];

        foreach ($layout['upper']['rows'] as $row) {
            $columns = [];

            foreach ($row['columns'] as $column) {
                $columns[] = [
                    'span' => (string) $column['span'], 'align' => $column['align'],
                    'blocks' => array_map(fn (array $block): array => ['type' => $block['type'], 'data' => $block['type'] === 'social' ? $this->socialToFields($block['settings']) : $block['settings']], $column['blocks']),
                ];
            }

            $rows[] = ['columns' => $columns];
        }

        return [
            'upper' => ['enabled' => $layout['upper']['enabled'], 'padding' => $layout['upper']['padding'], 'bg' => $layout['upper']['bg'], 'text' => $layout['upper']['text'],
                'border_color' => $layout['upper']['border_color'], 'border_width' => (string) $layout['upper']['border_width'], 'rows' => $rows],
            'lower' => $layout['lower'],
        ];
    }

    /**
     * The submitted state as a layout, plus the map that turns a validator path back into the form field it belongs to. Filament's getState() hands the lists back with numeric keys, but
     * the fields of the form are named by the keys the browser holds (`$this->data`): the two are walked in step, so an error lands on the field that shows it.
     *
     * @param  array<string, mixed>  $state  the dehydrated state
     * @param  array<string, mixed>  $raw  the form's own state (the keys the fields are named by)
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function fromForm(array $state, array $raw): array
    {
        $map = [];
        $upper = is_array($state['upper'] ?? null) ? $state['upper'] : [];
        $rows = [];
        $rowKeys = $this->rawKeys($raw, 'upper.rows');
        $rowIndex = 0;

        foreach (is_array($upper['rows'] ?? null) ? array_values($upper['rows']) : [] as $row) {
            $rowKey = $this->keyAt($rowKeys, $rowIndex);
            $map["upper.rows.{$rowIndex}"] = "data.upper.rows.{$rowKey}";
            $columns = [];
            $columnKeys = $this->rawKeys($raw, "upper.rows.{$rowKey}.columns");
            $columnIndex = 0;

            foreach (is_array($row['columns'] ?? null) ? array_values($row['columns']) : [] as $column) {
                $columnKey = $this->keyAt($columnKeys, $columnIndex);
                $columnPath = "upper.rows.{$rowIndex}.columns.{$columnIndex}";
                $columnField = "data.upper.rows.{$rowKey}.columns.{$columnKey}";
                $map[$columnPath] = $columnField;
                $blocks = [];
                $rawColumn = $raw['upper']['rows'][$rowKey]['columns'][$columnKey] ?? [];
                $blockKeys = $this->rawKeys($raw, "upper.rows.{$rowKey}.columns.{$columnKey}.blocks");
                $blockIndex = 0;

                foreach (is_array($column['blocks'] ?? null) ? array_values($column['blocks']) : [] as $block) {
                    $blockKey = $this->keyAt($blockKeys, $blockIndex);
                    $blockField = "{$columnField}.blocks.{$blockKey}.data";
                    $map["{$columnPath}.blocks.{$blockIndex}"] = $blockField;

                    $data = is_array($block['data'] ?? null) ? $block['data'] : [];

                    // The social networks are one address field per network in the form (a repeater inside a block would be too deep for Livewire's limit of 10 levels of state): the
                    // fields become the validator's list again, and an error on the i-th item goes back to the field of its network.
                    if (($block['type'] ?? null) === 'social') {
                        $items = $this->socialFromFields($data);

                        foreach ($items as $position => $item) {
                            $map["{$columnPath}.blocks.{$blockIndex}.settings.items.{$position}"] = "{$blockField}.n_".$item['network'];
                        }

                        $data = ['items' => $items];
                    }

                    $blocks[] = ['type' => (string) ($block['type'] ?? ''), 'settings' => $data];
                    $blockIndex++;
                }

                $columns[] = ['span' => $column['span'] ?? 1, 'align' => $column['align'] ?? 'start', 'blocks' => $blocks];
                $columnIndex++;
            }

            $rows[] = ['columns' => $columns];
            $rowIndex++;
        }

        $lower = is_array($state['lower'] ?? null) ? $state['lower'] : [];
        $layout = [
            'upper' => [
                'enabled' => (bool) ($upper['enabled'] ?? false), 'padding' => $upper['padding'] ?? 'normal', 'bg' => $this->nullable($upper['bg'] ?? null), 'text' => $this->nullable($upper['text'] ?? null),
                'border_color' => $this->nullable($upper['border_color'] ?? null), 'border_width' => $upper['border_width'] ?? 1, 'rows' => $rows,
            ],
            'lower' => [
                'enabled' => (bool) ($lower['enabled'] ?? false), 'padding' => $lower['padding'] ?? 'compact', 'bg' => $this->nullable($lower['bg'] ?? null), 'text' => $this->nullable($lower['text'] ?? null),
                'align' => $lower['align'] ?? 'center', 'text_template' => (string) ($lower['text_template'] ?? ''),
            ],
        ];

        return [$layout, $map];
    }

    private function nullable(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    /**
     * The social block's list as the form's fields: `n_facebook` => address, one per network.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     */
    private function socialToFields(array $settings): array
    {
        $fields = [];

        foreach (HeaderCatalog::SOCIAL_NETWORKS as $network) {
            $fields['n_'.$network] = '';
        }

        foreach ($settings['items'] ?? [] as $item) {
            $fields['n_'.$item['network']] = $item['url'];
        }

        return $fields;
    }

    /**
     * The fields back as the validator's list: the networks that have an address, in the catalog's order.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{network: string, url: string}>
     */
    private function socialFromFields(array $data): array
    {
        $items = [];

        foreach (HeaderCatalog::SOCIAL_NETWORKS as $network) {
            $url = trim((string) ($data['n_'.$network] ?? ''));

            if ($url !== '') {
                $items[] = ['network' => $network, 'url' => $url];
            }
        }

        return $items;
    }

    /** Throws the validator's messages as the form's own field errors (the longest known prefix wins). @param  array<string, string>  $map */
    private function failWith(array $errors, array $map): never
    {
        uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $messages = [];

        foreach ($errors as $path => $message) {
            $field = null;

            foreach ($map as $prefix => $target) {
                if ($path !== $prefix && ! str_starts_with($path, $prefix.'.')) {
                    continue;
                }

                $rest = substr($path, strlen($prefix));
                $isBlock = str_ends_with($target, '.data');

                if ($isBlock && str_starts_with($rest, '.settings.')) {
                    $rest = substr($rest, strlen('.settings'));
                } elseif ($isBlock) {
                    $target = substr($target, 0, -strlen('.data'));
                }

                // a social item is ONE field in the form (its network's address): `.url` / `.network` do not exist there
                $field = preg_match('/\.data\.n_[a-z]+$/', $target) === 1 ? $target : $target.$rest;

                break;
            }

            $messages[$field ?? 'data.'.($path === 'upper' ? 'upper.rows' : $path)] = $message;
        }

        $this->failFields($messages);
    }

    // ---- the form --------------------------------------------------------------------------------------------------------------------

    /** The Filament field for one setting of the catalog schema. @param  array<string, mixed>  $field */
    private static function field(string $name, array $field): Component
    {
        $label = __('footer.settings.'.$name);

        return match ($field['kind']) {
            'int' => TextInput::make($name)->label($label)->numeric()->integer()->minValue((int) ($field['min'] ?? 0))->maxValue((int) ($field['max'] ?? 9999))->default($field['default'] ?? null),
            'bool' => Toggle::make($name)->label($label)->default((bool) ($field['default'] ?? false)),
            'enum' => Select::make($name)->label($label)->native(false)->options(array_combine($field['options'], array_map(static fn (string $o): string => __('footer.options.'.$o), $field['options'])))->default($field['default'] ?? $field['options'][0]),
            'textarea' => Textarea::make($name)->label($label)->rows(3)->maxLength((int) ($field['max'] ?? 400))->default(''),
            'menu' => Select::make($name)->label($label)->native(false)->placeholder(__('footer.options.footer_location'))
                ->options(static fn (): array => MenuModel::query()->orderBy('name')->pluck('name', 'id')->all())->default(null),
            'html_block' => Select::make($name)->label($label)->native(false)->required()
                ->options(static fn (): array => HtmlBlockModel::query()->orderBy('title')->pluck('title', 'id')->all()),
            'payments' => CheckboxList::make($name)->label($label)->columns(2)->default([])
                ->options(array_combine(FooterCatalog::PAYMENTS, array_map(static fn (string $m): string => __('footer.payments.'.$m), FooterCatalog::PAYMENTS))),
            // One address field per network (not a repeater: the state of a block deep in a row and a column would pass Livewire's limit of 10 levels).
            'social' => Fieldset::make($label)->schema(array_map(
                static fn (string $network): TextInput => TextInput::make('n_'.$network)->label(__('header.social.'.$network))->placeholder('https://')->maxLength(300)->default(''),
                HeaderCatalog::SOCIAL_NETWORKS,
            ))->columns(2),
            default => TextInput::make($name)->label($label)->default($field['default'] ?? '')->maxLength((int) ($field['max'] ?? 500)),
        };
    }

    /** The block list of a column: a block per type of the catalog. */
    private static function blocks(): Builder
    {
        $blocks = [];

        foreach (FooterCatalog::all() as $type => $descriptor) {
            $fields = array_map(static fn (string $name, array $definition): Component => static::field($name, $definition), array_keys($descriptor['schema']), array_values($descriptor['schema']));

            if ($type === 'newsletter') {
                $fields[] = Text::make(__('footer.newsletter_note'))->color('warning')->size('sm')->visible(static fn (): bool => ! Route::has(FooterCatalog::NEWSLETTER_ROUTE));
            }

            $blocks[] = Block::make($type)
                ->label($descriptor['builtin'] ? __($descriptor['label']) : $descriptor['label'])
                ->schema($fields)
                ->columns(2);
        }

        return Builder::make('blocks')
            ->label(__('footer.fields.blocks'))
            ->blocks($blocks)
            ->maxItems(FooterLayout::MAX_BLOCKS_PER_COLUMN)
            ->collapsible()
            ->collapsed()
            ->blockNumbers(false)
            ->addActionLabel(__('footer.add_block'))
            ->extraItemActions([
                Action::make('move_prev')->label(__('footer.move_prev'))->icon('heroicon-m-arrow-left')->iconButton()
                    ->action(static fn (array $arguments, Builder $component, self $livewire) => $livewire->moveBlock($component->getStatePath(), (string) ($arguments['item'] ?? ''), -1)),
                Action::make('move_next')->label(__('footer.move_next'))->icon('heroicon-m-arrow-right')->iconButton()
                    ->action(static fn (array $arguments, Builder $component, self $livewire) => $livewire->moveBlock($component->getStatePath(), (string) ($arguments['item'] ?? ''), 1)),
            ])
            ->default([]);
    }

    /** An optional colour: a picker with a clear button (an empty colour = the colour of «Облик»). */
    private static function color(string $name, string $label): ColorPicker
    {
        return ColorPicker::make($name)->label($label)->hex()->live(debounce: 400)->helperText(__('footer.fields.color_hint'))
            ->suffixAction(Action::make('clear_'.str_replace('.', '_', $name))->icon('heroicon-o-x-mark')->tooltip(__('footer.fields.color_hint'))->action(static fn (\Filament\Schemas\Components\Utilities\Set $set) => $set($name, null)))
            ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== null && $value !== '' && ! AppearanceSettings::isHex($value)) {
                    $fail(__('footer.errors.bad_color'));
                }
            });
    }

    /** The warning under a level's text/background pair: shown only when both are chosen here and the ratio is under 4.5 (the effective pair is also checked on save). */
    private static function warning(string $level): Text
    {
        return Text::make(static fn (Get $get): string => __('footer.notices.low_contrast', ['level' => __('footer.notices.level_'.$level), 'ratio' => number_format(AppearanceSettings::contrastRatio((string) $get("{$level}.text"), (string) $get("{$level}.bg")), 2)]))
            ->color('warning')
            ->size('sm')
            ->visible(static fn (Get $get): bool => AppearanceSettings::isHex($get("{$level}.text")) && AppearanceSettings::isHex($get("{$level}.bg"))
                && AppearanceSettings::contrastRatio((string) $get("{$level}.text"), (string) $get("{$level}.bg")) < AppearanceSettings::MIN_CONTRAST);
    }

    private static function paddings(): array
    {
        return array_combine(FooterLayout::PADDINGS, array_map(static fn (string $p): string => __('footer.options.'.$p), FooterLayout::PADDINGS));
    }

    private static function aligns(): array
    {
        return array_combine(FooterLayout::ALIGNS, array_map(static fn (string $a): string => __('footer.options.'.$a), FooterLayout::ALIGNS));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                HelpLink::component('appearance-footer', 'appearance'),
                Section::make(__('footer.sections.upper'))
                    ->collapsible()
                    ->schema([
                        Toggle::make('upper.enabled')->label(__('footer.fields.enabled')),
                        Select::make('upper.padding')->label(__('footer.fields.padding'))->native(false)->options(static::paddings())->required(),
                        static::color('upper.bg', __('footer.fields.bg')),
                        static::color('upper.text', __('footer.fields.text')),
                        static::warning('upper'),
                        static::color('upper.border_color', __('footer.fields.border_color')),
                        Select::make('upper.border_width')->label(__('footer.fields.border_width'))->native(false)
                            ->options(array_combine(array_map('strval', FooterLayout::BORDER_WIDTHS), array_map(static fn (int $w): string => __('footer.options.border_'.$w), FooterLayout::BORDER_WIDTHS)))->required(),
                        Repeater::make('upper.rows')->label(__('footer.fields.rows'))
                            ->minItems(1)->maxItems(FooterLayout::MAX_ROWS)->addActionLabel(__('footer.add_row'))->collapsible()->itemLabel(static fn (): string => __('footer.fields.row'))->columnSpanFull()
                            ->schema([
                                Repeater::make('columns')->label(__('footer.fields.columns'))
                                    ->minItems(1)->maxItems(FooterLayout::MAX_COLUMNS)->addActionLabel(__('footer.add_column'))->collapsible()->itemLabel(static fn (): string => __('footer.fields.column'))
                                    ->schema([
                                        Select::make('span')->label(__('footer.fields.span'))->native(false)->options(['1' => __('footer.options.span_1'), '2' => __('footer.options.span_2')])->default('1')->required(),
                                        Select::make('align')->label(__('footer.fields.align'))->native(false)->options(static::aligns())->default('start')->required(),
                                        static::blocks()->columnSpanFull(),
                                    ])->columns(2),
                            ]),
                    ])
                    ->columns(2),
                Section::make(__('footer.sections.lower'))
                    ->collapsible()
                    ->schema([
                        Toggle::make('lower.enabled')->label(__('footer.fields.enabled')),
                        Select::make('lower.padding')->label(__('footer.fields.padding'))->native(false)->options(static::paddings())->required(),
                        static::color('lower.bg', __('footer.fields.bg')),
                        static::color('lower.text', __('footer.fields.text')),
                        static::warning('lower'),
                        Select::make('lower.align')->label(__('footer.fields.lower_align'))->native(false)->options(static::aligns())->required(),
                        TextInput::make('lower.text_template')->label(__('footer.fields.text_template'))->helperText(__('footer.fields.text_hint'))->maxLength(FooterLayout::MAX_TEMPLATE)->columnSpanFull(),
                    ])
                    ->columns(2),
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
            Action::make('save_draft')->label(__('footer.actions.save_draft'))->color('gray')->action(fn () => $this->saveDraft()),
            Action::make('preview')->label(__('footer.actions.preview'))->color('gray')->icon('heroicon-o-eye')->action(fn () => $this->preview()),
            Action::make('discard')->label(__('footer.actions.discard'))->color('gray')->requiresConfirmation()->action(fn () => $this->discard()),
            Action::make('default')->label(__('footer.actions.default'))->color('gray')->requiresConfirmation()->action(fn () => $this->resetToDefault()),
            Action::make('publish')->label(__('footer.actions.publish'))->requiresConfirmation()->action(fn () => $this->publish()),
        ];
    }

    // ---- the actions ------------------------------------------------------------------------------------------------------------------

    /** Validates the form's state; returns the NORMALISED layout or throws the field errors. */
    private function validated(): array
    {
        [$layout, $map] = $this->fromForm($this->form->getState(), $this->data ?? []);
        $validation = FooterValidator::validate($layout);

        if (! $validation->passes()) {
            $this->failWith($validation->errors, $map);
        }

        return $validation->layout;
    }

    public function saveDraft(): void
    {
        $this->authorizeAction();
        $layout = $this->validated();
        FooterDrafts::saveDraft($layout);

        Notification::make()->title(__('footer.notices.draft_saved'))->success()->send();
        $this->warnAboutContrast($layout);
    }

    public function preview(): void
    {
        $this->saveDraft();
        $this->js('window.open('.json_encode(FooterPreviewController::previewUrl(), JSON_UNESCAPED_SLASHES).', "_blank")');
    }

    public function publish(): void
    {
        $this->authorizeAction();
        $layout = $this->validated();

        try {
            FooterDrafts::publish($layout);
        } catch (\InvalidArgumentException) {
            $this->failFields(['data.upper.rows' => __('footer.errors.too_large')]);
        }

        Notification::make()->title(__('footer.notices.published'))->success()->send();
        $this->warnAboutContrast($layout);
    }

    /** «Върни публикуваното»: the draft is thrown away; the form shows the published layout again (or the default when nothing was ever published). */
    public function discard(): void
    {
        $this->authorizeAction();
        FooterDrafts::discard();
        $this->form->fill($this->toForm($this->startingLayout()));

        Notification::make()->title(__('footer.notices.discarded'))->success()->send();
    }

    /** «Върни по подразбиране»: the seeded footer goes INTO THE FORM only; nothing is stored until the merchant saves or publishes. */
    public function resetToDefault(): void
    {
        $this->authorizeAction();
        $this->form->fill($this->toForm(FooterLayout::default()));

        Notification::make()->title(__('footer.notices.defaulted'))->success()->send();
    }

    /**
     * Moves one block to the end of the previous (-1) or next (+1) column of the SAME row: the form's own state is changed, nothing is stored until the merchant saves. A column that
     * does not exist that way, or one that is full, is a notice and nothing moves.
     */
    public function moveBlock(string $path, string $item, int $direction): void
    {
        $this->authorizeAction();

        if (preg_match('/^data\.upper\.rows\.([^.]+)\.columns\.([^.]+)\.blocks$/', $path, $m) !== 1 || ! in_array($direction, [-1, 1], true)) {
            return;
        }

        $data = $this->data ?? [];
        $columns = $data['upper']['rows'][$m[1]]['columns'] ?? null;
        $block = $data['upper']['rows'][$m[1]]['columns'][$m[2]]['blocks'][$item] ?? null;

        if (! is_array($columns) || ! is_array($block)) {
            return;
        }

        $keys = array_keys($columns);
        $target = $keys[(int) array_search($m[2], $keys, true) + $direction] ?? null;

        if ($target === null || (int) array_search($m[2], $keys, true) + $direction < 0) {
            Notification::make()->title(__('footer.notices.no_column'))->warning()->send();

            return;
        }

        if (count($columns[$target]['blocks'] ?? []) >= FooterLayout::MAX_BLOCKS_PER_COLUMN) {
            Notification::make()->title(__('footer.errors.too_many_in_column', ['max' => FooterLayout::MAX_BLOCKS_PER_COLUMN]))->warning()->send();

            return;
        }

        unset($data['upper']['rows'][$m[1]]['columns'][$m[2]]['blocks'][$item]);
        $data['upper']['rows'][$m[1]]['columns'][$target]['blocks'][$item] = $block;
        $this->data = $data;
    }

    private function authorizeAction(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** A non-blocking notice for each level whose EFFECTIVE text / background pair (the Облик colour where none is chosen here) is under 4.5:1. @param  array<string, mixed>  $layout */
    private function warnAboutContrast(array $layout): void
    {
        $settings = app(SiteSettingsRepository::class);
        $stored = static fn (string $short): string => (function () use ($settings, $short): string {
            [$key, , $default] = AppearanceSettings::COLORS[$short];
            $value = $settings->get($key);

            return is_string($value) && AppearanceSettings::isHex($value) ? strtolower($value) : $default;
        })();

        foreach (['upper', 'lower'] as $level) {
            $text = $layout[$level]['text'] ?? $stored('footer_text');
            $background = $layout[$level]['bg'] ?? $stored('footer_bg');
            $ratio = AppearanceSettings::contrastRatio($text, $background);

            if ($ratio < AppearanceSettings::MIN_CONTRAST) {
                Notification::make()->title(__('footer.notices.low_contrast', ['level' => __('footer.notices.level_'.$level), 'ratio' => number_format($ratio, 2)]))->warning()->send();
            }
        }
    }
}
