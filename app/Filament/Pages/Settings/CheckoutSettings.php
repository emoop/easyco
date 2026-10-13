<?php

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Settings\CheckoutFormSettings;
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
use Filament\Schemas\Schema;
use InvalidArgumentException;

/**
 * "Checkout" settings: TWO sections. "Fields at checkout" (checkout-form-v2-design.md section 6.6): the optional "call me before shipping" and
 * newsletter boxes, the terms acceptance (on by default; the version is set automatically when the URL changes, or by the "terms updated" button)
 * and the privacy and terms links. And (checkout-region-and-note-design.md section 5) "Region (state) field" — for every country the
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

        $form = app(CheckoutFormSettings::class);

        $this->form->fill([
            'default' => $policy->defaultMode()->value,
            'exceptions' => $exceptions,
            'call_before_shipping_enabled' => $form->callBeforeShippingEnabled(),
            'newsletter_enabled' => $form->newsletterEnabled(),
            'terms_enabled' => $form->termsEnabled(),
            'terms_url' => $form->termsUrl() ?? '',
            'terms_version' => $form->termsVersion() ?? '',
            'privacy_url' => $form->privacyUrl() ?? '',
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
                Section::make(__('settings.checkout_page.fields_section'))
                    ->description(__('settings.checkout_page.fields_help'))
                    ->schema([
                        Toggle::make('call_before_shipping_enabled')
                            ->label(__('settings.checkout_page.call_before_label'))
                            ->helperText(__('settings.checkout_page.call_before_help')),
                        Toggle::make('newsletter_enabled')
                            ->label(__('settings.checkout_page.newsletter_label'))
                            ->helperText(__('settings.checkout_page.newsletter_help')),
                        Toggle::make('terms_enabled')
                            ->label(__('settings.checkout_page.terms_label'))
                            ->helperText(__('settings.checkout_page.terms_help'))
                            ->live(),
                        TextInput::make('terms_url')
                            ->label(__('settings.checkout_page.terms_url_label'))
                            ->placeholder('https://… / /terms')
                            ->maxLength(2048)
                            ->rules([fn (): Closure => static::linkRule()])
                            ->live(onBlur: true),
                        // Read-only: nobody types a version. It follows the URL (set to today when the URL changes) and the button below.
                        TextInput::make('terms_version')
                            ->label(__('settings.checkout_page.terms_version_label'))
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText(__('settings.checkout_page.terms_version_help')),
                        Actions::make([
                            Action::make('bump_terms_version')
                                ->label(__('settings.checkout_page.terms_updated_button'))
                                ->color('gray')
                                ->action(fn () => $this->bumpTermsVersion()),
                        ])->key('terms-version-actions'),
                        Text::make(__('settings.checkout_page.warning_terms_without_url'))
                            ->color('danger')
                            ->visible(fn ($get): bool => (bool) $get('terms_enabled') && trim((string) $get('terms_url')) === ''),
                        TextInput::make('privacy_url')
                            ->label(__('settings.checkout_page.privacy_url_label'))
                            ->placeholder('https://… / /privacy')
                            ->maxLength(2048)
                            ->rules([fn (): Closure => static::linkRule()])
                            ->live(onBlur: true),
                        Text::make(__('settings.checkout_page.warning_no_privacy_url'))
                            ->color('warning')
                            ->visible(fn ($get): bool => trim((string) $get('privacy_url')) === ''),
                    ]),
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

    /** The links: an http(s) URL or a same-site path (`/politika`), at most 2048 characters: the very test the page applies before it writes one. */
    private static function linkRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && trim($value) !== '' && ! CheckoutFormSettings::isSafeLink(trim($value))) {
                $fail(__('settings.checkout_page.link_invalid'));
            }
        };
    }

    /** "The terms were updated": today's date becomes the version at once, so a customer who loaded the page earlier is asked again. */
    public function bumpTermsVersion(): void
    {
        $today = static::today();

        app(SiteSettingsRepository::class)->set(CheckoutFormSettings::TERMS_VERSION_KEY, $today);
        $this->data['terms_version'] = $today;

        Notification::make()->title(__('settings.checkout_page.terms_version_bumped', ['version' => $today]))->success()->send();
    }

    /** The version string: today's date in the store's own calendar (Y-m-d), never typed by a person. */
    private static function today(): string
    {
        return now(app(\App\Settings\StoreTimezone::class)->zone())->format('Y-m-d');
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
        $form = app(CheckoutFormSettings::class);

        // The optional boxes: '1' = shown, absent = off. The terms: '0' = off, absent = ON (the EU default). A link is stored trimmed, or forgotten.
        foreach ([CheckoutFormSettings::CALL_BEFORE_SHIPPING_KEY => 'call_before_shipping_enabled', CheckoutFormSettings::NEWSLETTER_KEY => 'newsletter_enabled'] as $key => $field) {
            ! empty($data[$field]) ? $settings->set($key, '1') : $settings->forget($key);
        }

        ($data['terms_enabled'] ?? true) ? $settings->forget(CheckoutFormSettings::TERMS_ENABLED_KEY) : $settings->set(CheckoutFormSettings::TERMS_ENABLED_KEY, '0');

        $newTermsUrl = trim((string) ($data['terms_url'] ?? ''));
        $newPrivacyUrl = trim((string) ($data['privacy_url'] ?? ''));
        $oldTermsUrl = $form->termsUrl() ?? '';

        $newTermsUrl === '' ? $settings->forget(CheckoutFormSettings::TERMS_URL_KEY) : $settings->set(CheckoutFormSettings::TERMS_URL_KEY, $newTermsUrl);
        $newPrivacyUrl === '' ? $settings->forget(CheckoutFormSettings::PRIVACY_URL_KEY) : $settings->set(CheckoutFormSettings::PRIVACY_URL_KEY, $newPrivacyUrl);

        // A different terms page is a different text: the version becomes today's date by itself.
        if ($newTermsUrl !== '' && $newTermsUrl !== $oldTermsUrl) {
            $settings->set(CheckoutFormSettings::TERMS_VERSION_KEY, static::today());
            $this->data['terms_version'] = static::today();
        }

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
