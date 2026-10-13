<?php

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Settings\CheckoutRegionPolicy;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\CountryNames;
use App\Settings\RegionMode;
use App\Settings\StoreLocale;
use BackedEnum;
use Closure;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use InvalidArgumentException;

/**
 * "Checkout" settings (checkout-region-and-note-design.md section 5): today one section, "Region (state) field" — for every country the
 * region of a street address is optional, required or hidden, with a default and a list of exceptions by country. Same shape as
 * BankTransferSettings: Permission::SETTINGS_MANAGE, SiteSettingsRepository, one Save.
 *
 * THE RULES LIVE IN CheckoutRegionPolicy (normalizeCountries() / encodeCountries()), the class the checkout reads: the page cannot save
 * a value the reader would refuse (an unknown country, a repeated country, an unknown mode). Keeping the store clean: the default
 * `optional` forgets `checkout.region_default`, an empty list of exceptions forgets `checkout.region_countries`.
 *
 * AUDIT: none beyond the success notification, exactly like the other settings pages (BankTransferSettings, MailSettings and
 * CatalogSettings write through SiteSettingsRepository and record nothing). Staff are not bound by this rule: it governs what a
 * customer types.
 */
class CheckoutSettings extends Page
{
    use AuthorizesViaStaffPermission;

    /** @var array<string, mixed> */
    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    public function getTitle(): string
    {
        return __('settings.checkout_page.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.checkout_page.navigation_label');
    }

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::ADMIN;
    }

    public static function getNavigationSort(): ?int
    {
        return 33;
    }

    protected static function accessPermission(): ?Permission
    {
        return Permission::SETTINGS_MANAGE;
    }

    public static function canAccess(): bool
    {
        return static::staffCanForAction(static::accessPermission());
    }

    public function mount(): void
    {
        $policy = app(CheckoutRegionPolicy::class);
        $exceptions = [];

        foreach ($policy->overrides() as $country => $mode) {
            $exceptions[] = ['country' => $country, 'mode' => $mode->value];
        }

        $this->form->fill([
            'default' => $policy->defaultMode()->value,
            'exceptions' => $exceptions,
        ]);
    }

    /** @return array<string, string> the three modes, labelled */
    public static function modeOptions(): array
    {
        return [
            RegionMode::OPTIONAL->value => __('settings.checkout_page.mode.optional'),
            RegionMode::REQUIRED->value => __('settings.checkout_page.mode.required'),
            RegionMode::HIDDEN->value => __('settings.checkout_page.mode.hidden'),
        ];
    }

    /**
     * The gray sentence under the form: what the customer will see, for the default and every exception.
     *
     * @param  list<array<string, mixed>>  $rows  the exceptions as typed (a row without a known country is skipped)
     */
    public static function factLine(string $default, array $rows): string
    {
        $default = RegionMode::tryFrom($default) ?? RegionMode::OPTIONAL;
        $names = CountryNames::forLocale(app(StoreLocale::class)->current());
        $exceptions = [];

        foreach ($rows as $row) {
            $country = is_string($row['country'] ?? null) ? strtoupper($row['country']) : '';
            $mode = is_string($row['mode'] ?? null) ? RegionMode::tryFrom($row['mode']) : null;

            if ($mode !== null && isset($names[$country])) {
                $exceptions[] = $names[$country].' — '.__('settings.checkout_page.fact.'.$mode->value);
            }
        }

        $line = __('settings.checkout_page.fact_default.'.$default->value);

        return $exceptions === [] ? $line.'.' : $line.' '.__('settings.checkout_page.fact_except').' '.implode('; ', $exceptions).'.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('settings.checkout_page.region_section'))
                    ->description(__('settings.checkout_page.region_help'))
                    ->schema([
                        Select::make('default')
                            ->label(__('settings.checkout_page.default_label'))
                            ->options(static::modeOptions())
                            ->default(RegionMode::OPTIONAL->value)
                            ->required()
                            ->live(),
                        Repeater::make('exceptions')
                            ->label(__('settings.checkout_page.exceptions_label'))
                            ->addActionLabel(__('settings.checkout_page.add_exception'))
                            ->schema([
                                Select::make('country')
                                    ->label(__('settings.checkout_page.country_label'))
                                    ->options(fn (): array => CountryNames::forLocale(app(StoreLocale::class)->current()))
                                    ->searchable()
                                    ->required()
                                    ->distinct()
                                    ->live(),
                                Select::make('mode')
                                    ->label(__('settings.checkout_page.mode_label'))
                                    ->options(static::modeOptions())
                                    ->required()
                                    ->live(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->rules([fn (): Closure => static::exceptionsRule()])
                            ->live(),
                        Text::make(fn ($get): string => static::factLine((string) $get('default'), array_values((array) $get('exceptions'))))
                            ->color('gray')
                            ->size('sm'),
                    ]),
            ])
            ->statePath('data');
    }

    /** The reader's own validation of the exceptions list, as a form rule: the page cannot accept what CheckoutRegionPolicy would refuse. */
    private static function exceptionsRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            try {
                CheckoutRegionPolicy::normalizeCountries(array_values((array) $value));
            } catch (InvalidArgumentException) {
                $fail(__('settings.checkout_page.exceptions_invalid'));
            }
        };
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
        ]);
    }

    protected function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label(__('settings.checkout_page.save_label'))
                        ->submit('save'),
                ])->key('form-actions'),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = app(SiteSettingsRepository::class);

        $default = RegionMode::tryFrom((string) ($data['default'] ?? '')) ?? RegionMode::OPTIONAL;
        // The same validation the reader applies; the form rule above has already refused a bad list, this is the guard behind it.
        $countries = CheckoutRegionPolicy::normalizeCountries(array_values((array) ($data['exceptions'] ?? [])));

        if ($default === RegionMode::OPTIONAL) {
            $settings->forget(CheckoutRegionPolicy::DEFAULT_KEY);
        } else {
            $settings->set(CheckoutRegionPolicy::DEFAULT_KEY, $default->value);
        }

        if ($countries === []) {
            $settings->forget(CheckoutRegionPolicy::COUNTRIES_KEY);
        } else {
            $settings->set(CheckoutRegionPolicy::COUNTRIES_KEY, CheckoutRegionPolicy::encodeCountries($countries));
        }

        Notification::make()
            ->title(__('settings.checkout_page.saved_notification'))
            ->success()
            ->send();
    }
}
