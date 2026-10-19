<?php

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Support\HelpLink;
use App\Rules\PlainText;
use App\Services\ActivityLogger;
use App\Settings\AppearanceSettings as Appearance;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\AppearanceCompiler;
use App\Storefront\Appearance\StorefrontAppearance;
use BackedEnum;
use Closure;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * «Облик» / "Appearance" (stage D1, storefront-customization-design.md 3): the colours, fonts, corners and button style of the storefront and its top
 * banner. Admin group, `content_manage` (the same people as «Витрина»). No code and no CSS is ever typed here: colours come from a hex colour picker and
 * are validated `#RRGGBB`, fonts / radius / button style from closed lists, texts are plain and bounded, the banner link is a root-relative path or an
 * https address. On save only the values that DIFFER from their defaults are stored; they are compiled into one small stylesheet (AppearanceCompiler)
 * and the application-cache entry of the reader is REPLACED (StorefrontAppearance::refresh()). Contrast warnings (WCAG AA 4.5:1) are shown under the
 * colour pairs that matter, and never block a save.
 */
class AppearanceSettings extends Page
{
    use ServerSideValidation;
    use AuthorizesViaStaffPermission;

    /** @var array<string, mixed> */
    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-swatch';

    public function getTitle(): string
    {
        return __('appearance.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('appearance.navigation_label');
    }

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::ADMIN;
    }

    public static function getNavigationSort(): ?int
    {
        return 35;
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

    public function mount(): void
    {
        $settings = app(SiteSettingsRepository::class);
        $defaults = Appearance::defaults();
        $stored = $settings->getMany([
            ...array_keys($defaults),
            Appearance::BANNER_ENABLED, Appearance::BANNER_TEXT, Appearance::BANNER_URL, Appearance::BANNER_LINK_LABEL,
            Appearance::BANNER_STARTS_AT, Appearance::BANNER_ENDS_AT,
        ]);
        $state = [];

        foreach (Appearance::COLORS as $name => [$key, , $default]) {
            $value = $stored[$key] ?? null;
            $state['c_'.$name] = Appearance::isHex($value) ? strtolower($value) : $default;
        }

        foreach (['font_body' => Appearance::FONT_BODY, 'font_heading' => Appearance::FONT_HEADING, 'radius' => Appearance::RADIUS, 'button' => Appearance::BUTTON] as $field => $key) {
            $value = $stored[$key] ?? null;
            $state[$field] = Appearance::isValid($key, $value) ? $value : $defaults[$key];
        }

        $state += [
            'banner_enabled' => ($stored[Appearance::BANNER_ENABLED] ?? null) === '1',
            'banner_text' => $stored[Appearance::BANNER_TEXT] ?? '',
            'banner_url' => $stored[Appearance::BANNER_URL] ?? '',
            'banner_link_label' => $stored[Appearance::BANNER_LINK_LABEL] ?? '',
            'banner_starts_at' => $stored[Appearance::BANNER_STARTS_AT] ?? null,
            'banner_ends_at' => $stored[Appearance::BANNER_ENDS_AT] ?? null,
        ];

        $this->form->fill($state);
    }

    // ---- the form --------------------------------------------------------------------------------------------------------------------

    private static function color(string $name): ColorPicker
    {
        return ColorPicker::make('c_'.$name)
            ->label(__('appearance.colors.'.$name))
            ->hex()
            ->required()
            ->live(debounce: 400)
            ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                if (! Appearance::isHex($value)) {
                    $fail(__('appearance.errors.color'));
                }
            });
    }

    /** The contrast warning under a pair: shown only when both colours are valid and the ratio is under 4.5. */
    private static function warning(string $pair): Text
    {
        [$text, $background] = Appearance::CONTRAST_PAIRS[$pair];

        return Text::make(static function (Get $get) use ($text, $background): string {
            return __('appearance.contrast_warning', ['ratio' => number_format(Appearance::contrastRatio((string) $get('c_'.$text), (string) $get('c_'.$background)), 2)]);
        })
            ->color('warning')
            ->size('sm')
            ->visible(static fn (Get $get): bool => Appearance::isHex($get('c_'.$text)) && Appearance::isHex($get('c_'.$background))
                && Appearance::contrastRatio((string) $get('c_'.$text), (string) $get('c_'.$background)) < Appearance::MIN_CONTRAST);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('appearance.sections.colors'))
                    ->description(__('appearance.sections.colors_help'))
                    ->schema([
                        HelpLink::component('appearance-colors', 'appearance'),
                        static::color('background'),
                        static::color('text'),
                        static::warning('text_on_background'),
                        static::color('muted'),
                        static::color('primary'),
                        static::color('on_primary'),
                        static::warning('on_primary_on_primary'),
                        static::color('accent'),
                        static::color('border'),
                        static::color('header_bg'),
                        static::color('header_text'),
                        static::warning('header'),
                        static::color('footer_bg'),
                        static::color('footer_text'),
                        static::warning('footer'),
                    ])
                    ->columns(2),
                Section::make(__('appearance.sections.fonts'))
                    ->description(__('appearance.sections.fonts_help'))
                    ->schema([
                        HelpLink::component('appearance-fonts', 'appearance'),
                        Select::make('font_body')
                            ->label(__('appearance.fields.font_body'))
                            ->options(Appearance::FONTS)
                            ->required()
                            ->native(false),
                        Select::make('font_heading')
                            ->label(__('appearance.fields.font_heading'))
                            ->options(['same' => __('appearance.fonts.same')] + Appearance::FONTS)
                            ->required()
                            ->native(false),
                        Select::make('radius')
                            ->label(__('appearance.fields.radius'))
                            ->options(array_combine(array_keys(Appearance::RADII), array_map(static fn (string $key): string => __('appearance.radii.'.$key), array_keys(Appearance::RADII))))
                            ->required()
                            ->native(false),
                        Select::make('button')
                            ->label(__('appearance.fields.button'))
                            ->options(array_combine(Appearance::BUTTONS, array_map(static fn (string $key): string => __('appearance.buttons.'.$key), Appearance::BUTTONS)))
                            ->required()
                            ->native(false),
                    ])
                    ->columns(2),
                Section::make(__('appearance.sections.banner'))
                    ->description(__('appearance.sections.banner_help'))
                    ->schema([
                        HelpLink::component('appearance-banner', 'appearance'),
                        Toggle::make('banner_enabled')
                            ->label(__('appearance.fields.banner_enabled'))
                            ->live(),
                        TextInput::make('banner_text')
                            ->label(__('appearance.fields.banner_text'))
                            ->maxLength(Appearance::BANNER_TEXT_MAX)
                            ->rule(new PlainText)
                            ->required(static fn (Get $get): bool => (bool) $get('banner_enabled'))
                            ->live(debounce: 300)
                            ->hint(static fn ($state): string => __('appearance.counter', ['count' => mb_strlen((string) $state), 'max' => Appearance::BANNER_TEXT_MAX])),
                        TextInput::make('banner_url')
                            ->label(__('appearance.fields.banner_url'))
                            ->helperText(__('appearance.fields.banner_url_help'))
                            ->maxLength(500)
                            ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                                if (is_string($value) && trim($value) !== '' && ! Appearance::isSafeBannerUrl(trim($value))) {
                                    $fail(__('appearance.errors.url'));
                                }
                            }),
                        TextInput::make('banner_link_label')
                            ->label(__('appearance.fields.banner_link_label'))
                            ->helperText(__('appearance.fields.banner_link_label_help'))
                            ->maxLength(Appearance::BANNER_LABEL_MAX)
                            ->rule(new PlainText),
                        static::color('banner_text'),
                        static::color('banner_bg'),
                        static::warning('banner'),
                        DateTimePicker::make('banner_starts_at')
                            ->label(__('appearance.fields.banner_starts_at'))
                            ->seconds(false)
                            ->format('Y-m-d H:i'),
                        DateTimePicker::make('banner_ends_at')
                            ->label(__('appearance.fields.banner_ends_at'))
                            ->seconds(false)
                            ->format('Y-m-d H:i')
                            ->rule(static fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $start = $get('banner_starts_at');

                                if (filled($value) && filled($start) && strcmp((string) $value, (string) $start) <= 0) {
                                    $fail(__('appearance.errors.ends_before_starts'));
                                }
                            }),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
        ]);
    }

    protected function getFormContentComponent(): Component
    {
        return static::withoutNativeValidation(Form::make([EmbeddedSchema::make('form')]))
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label(__('appearance.save_label'))
                        ->submit('save'),
                ])->key('form-actions'),
            ]);
    }

    // ---- save ------------------------------------------------------------------------------------------------------------------------

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = app(SiteSettingsRepository::class);
        $defaults = Appearance::defaults();
        $before = $this->snapshot($settings);

        // Only what DIFFERS from its default is stored (the key of a default value is forgotten): the compiled stylesheet then holds exactly that.
        $overrides = [];

        foreach (Appearance::COLORS as $name => [$key, , $default]) {
            $value = strtolower((string) ($data['c_'.$name] ?? $default));
            $overrides[$key] = $value;
        }

        foreach (['font_body' => Appearance::FONT_BODY, 'font_heading' => Appearance::FONT_HEADING, 'radius' => Appearance::RADIUS, 'button' => Appearance::BUTTON] as $field => $key) {
            $overrides[$key] = (string) ($data[$field] ?? $defaults[$key]);
        }

        $overrides = array_filter($overrides, static fn (string $value, string $key): bool => $value !== $defaults[$key], ARRAY_FILTER_USE_BOTH);

        foreach ($defaults as $key => $default) {
            array_key_exists($key, $overrides) ? $settings->set($key, $overrides[$key]) : $settings->forget($key);
        }

        $this->storeBanner($settings, $data);

        $previous = StorefrontAppearance::build([Appearance::COMPILED_KEY => $before['compiled']])['compiled']['hash'] ?? null;
        $compiled = app(AppearanceCompiler::class)->compile($overrides, $previous);

        $compiled === null ? $settings->forget(Appearance::COMPILED_KEY) : $settings->set(Appearance::COMPILED_KEY, (string) json_encode($compiled, JSON_THROW_ON_ERROR));

        // The reader's cache entry is REPLACED, so the next request already shows what was saved.
        app(StorefrontAppearance::class)->refresh();

        $this->journal($before, $this->snapshot($settings));

        Notification::make()
            ->title(__('appearance.saved_notification'))
            ->success()
            ->send();
    }

    /** @param  array<string, mixed>  $data */
    private function storeBanner(SiteSettingsRepository $settings, array $data): void
    {
        ! empty($data['banner_enabled']) ? $settings->set(Appearance::BANNER_ENABLED, '1') : $settings->forget(Appearance::BANNER_ENABLED);

        foreach ([
            Appearance::BANNER_TEXT => 'banner_text',
            Appearance::BANNER_URL => 'banner_url',
            Appearance::BANNER_LINK_LABEL => 'banner_link_label',
            Appearance::BANNER_STARTS_AT => 'banner_starts_at',
            Appearance::BANNER_ENDS_AT => 'banner_ends_at',
        ] as $key => $field) {
            $value = trim((string) ($data[$field] ?? ''));
            $value === '' ? $settings->forget($key) : $settings->set($key, $value);
        }
    }

    /** @return array<string, ?string> every stored value this page owns */
    private function snapshot(SiteSettingsRepository $settings): array
    {
        $keys = [...array_keys(Appearance::defaults()), Appearance::BANNER_ENABLED, Appearance::BANNER_TEXT, Appearance::BANNER_URL, Appearance::BANNER_LINK_LABEL, Appearance::BANNER_STARTS_AT, Appearance::BANNER_ENDS_AT];
        $values = $settings->getMany($keys);
        $values['compiled'] = $settings->get(Appearance::COMPILED_KEY);

        return $values;
    }

    /**
     * One activity-log line per SECTION that changed: who (the logger knows), which section, and only the word «saved». No value is written, not even a
     * colour (the activity log is a record that something changed; the compiled stylesheet is the record of what).
     *
     * @param  array<string, ?string>  $before
     * @param  array<string, ?string>  $after
     */
    private function journal(array $before, array $after): void
    {
        $sections = [
            'colors' => array_map(static fn (array $color): string => $color[0], array_values(Appearance::COLORS)),
            'fonts' => [Appearance::FONT_BODY, Appearance::FONT_HEADING, Appearance::RADIUS, Appearance::BUTTON],
            'banner' => [Appearance::BANNER_ENABLED, Appearance::BANNER_TEXT, Appearance::BANNER_URL, Appearance::BANNER_LINK_LABEL, Appearance::BANNER_STARTS_AT, Appearance::BANNER_ENDS_AT],
        ];
        $logger = app(ActivityLogger::class);

        foreach ($sections as $section => $keys) {
            foreach ($keys as $key) {
                if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                    $logger->logFieldChanged('storefront_appearance', 'appearance', $section, null, 'saved');

                    break;
                }
            }
        }
    }
}
