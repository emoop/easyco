<?php

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Pages\Design\DesignScreen;
use App\Filament\Support\HelpLink;
use App\Settings\AppearanceSettings as Appearance;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\AppearanceWriter;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Cookies\Contrast;
use BackedEnum;
use Closure;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
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
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * «Облик» / "Appearance" (stage D1, storefront-customization-design.md 3): the colours, fonts, corners and button style of the storefront. The top banner is
 * NOT edited here since OB-1: it is the «Дизайн» screen's «Топбанер» zone (this page only points to it). Admin group, `content_manage` (the same people as «Витрина»). No code and no CSS is ever typed here: colours come from a hex colour picker and
 * are validated `#RRGGBB`, fonts / radius / button style from closed lists. On save only the values that DIFFER from their defaults are stored; they are compiled into one small stylesheet (AppearanceCompiler)
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
        return NavigationGroup::DESIGN;
    }

    public static function getNavigationSort(): ?int
    {
        return 10;
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
        // DS-2b: the stored state comes from AppearanceWriter, the code the «Дизайн» screen's «Топбанер» zone saves through too
        $this->form->fill(AppearanceWriter::current());
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

    /**
     * DM-1: the message for a refused dark-mode button colour, or null when it is fine. ONE rule (Contrast::darkButton) for the live message, the field's validation and the writer.
     */
    private static function darkButtonProblem(mixed $value): ?string
    {
        $check = Contrast::darkButton($value);

        return match ($check['reason']) {
            null => null,
            'format' => __('appearance.errors.dark_button_format'),
            'button' => __('appearance.errors.dark_button', ['ratio' => number_format((float) $check['buttonRatio'], 2), 'min' => Contrast::COMPONENT_MIN]),
            default => __('appearance.errors.dark_button_text', ['ratio' => number_format((float) $check['textRatio'], 2), 'min' => Contrast::TEXT_MIN]),
        };
    }

    /** DM-1: the «Тъмен режим» section (always shown since CC-2: the dev flag is gone). */
    private static function darkSection(): Section
    {
        return Section::make(__('appearance.dark.section'))
            ->description(__('appearance.dark.section_help'))
            ->schema([
                Toggle::make('dark_mode')
                    ->label(__('appearance.dark.mode'))
                    ->helperText(__('appearance.dark.mode_help'))
                    ->live(),
                Text::make(__('appearance.dark.no_logo'))
                    ->color('warning')
                    ->size('sm')
                    ->visible(static fn (Get $get): bool => $get('dark_mode') === true && (app(StorefrontAppearance::class)->siteLogo()['dark'] ?? null) === null),
                ColorPicker::make('dark_button')
                    ->label(__('appearance.dark.button'))
                    ->helperText(__('appearance.dark.button_help'))
                    ->hex()
                    ->required()
                    ->live(debounce: 400)
                    ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                        ($problem = static::darkButtonProblem($value)) === null || $fail($problem);
                    }),
                Actions::make([
                    Action::make('dark_preset_orange')
                        ->label(__('appearance.dark.preset_orange'))
                        ->color('gray')
                        ->action(static fn (Set $set) => $set('dark_button', Appearance::DEFAULT_DARK_BUTTON)),
                    Action::make('dark_preset_white')
                        ->label(__('appearance.dark.preset_white'))
                        ->color('gray')
                        ->action(static fn (Set $set) => $set('dark_button', '#ffffff')),
                ])->key('dark-presets'),
                Text::make(static function (Get $get): string {
                    $check = Contrast::darkButton($get('dark_button'));

                    if ($check['reason'] === 'format') {
                        return (string) static::darkButtonProblem($get('dark_button'));
                    }

                    $summary = __('appearance.dark.summary', [
                        'text' => __($check['textColour'] === '#ffffff' ? 'appearance.dark.text_white' : 'appearance.dark.text_black'),
                        'button' => number_format((float) $check['buttonRatio'], 2),
                        'button_min' => Contrast::COMPONENT_MIN,
                        'text_ratio' => number_format((float) $check['textRatio'], 2),
                        'text_min' => Contrast::TEXT_MIN,
                    ]);

                    return $check['ok'] ? $summary : static::darkButtonProblem($get('dark_button')).' '.$summary;
                })
                    ->color(static fn (Get $get): string => Contrast::darkButton($get('dark_button'))['ok'] ? 'gray' : 'danger')
                    ->size('sm'),
            ]);
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
                static::darkSection(),
                // OB-1: the top banner is edited in the «Дизайн» screen only; this line says where it is (the same muted style as the other hints)
                Text::make(new HtmlString('<a href="'.e(DesignScreen::getUrl(['zone' => 'topbar'])).'">'.e(__('appearance.banner_moved')).'</a>'))->color('gray')->size('sm'),
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
        AppearanceWriter::save($this->form->getState());


        Notification::make()
            ->title(__('appearance.saved_notification'))
            ->success()
            ->send();
    }
}
