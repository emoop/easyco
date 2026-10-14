<?php

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Content\Models\PageModel;
use App\Settings\CheckoutFormSettings;
use App\Storefront\Pages\PageReader;
use App\Settings\CheckoutRegionPolicy;
use App\Settings\InvoiceSettings;
use App\Settings\OrderContextSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\CountryNames;
use App\Settings\RegionMode;
use App\Settings\StoreLocale;
use BackedEnum;
use Closure;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Radio;
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
            // A content page, or an external address (pages-design.md section 5): the page wins when one is chosen.
            'terms_source' => $form->termsPageId() !== null ? 'page' : 'url',
            'terms_page_id' => $form->termsPageId(),
            'privacy_source' => $form->privacyPageId() !== null ? 'page' : 'url',
            'privacy_page_id' => $form->privacyPageId(),
            // The invoice request (order-context-design.md 9.3) and the privacy window of the client address (7.2), stage C2-A.
            'invoice_enabled' => app(InvoiceSettings::class)->enabled(),
            'invoice_rules' => $this->invoiceRuleRows(),
            'ip_full_retention_days' => app(OrderContextSettings::class)->ipFullRetentionDays(),
        ]);
    }

    /** @return list<array<string, mixed>> the stored per-country invoice rules as the repeater's rows */
    private function invoiceRuleRows(): array
    {
        $rows = [];

        foreach (app(InvoiceSettings::class)->rules() as $country => $rule) {
            $rows[] = [
                'country' => $country,
                'tax_id_label' => $rule->taxIdLabel ?? '',
                'tax_id_required_for_company' => $rule->taxIdRequiredForCompany,
                'tax_id_pattern' => $rule->taxIdPattern ?? '',
                'registration_number_label' => $rule->registrationNumberLabel ?? '',
                'registration_number_required' => $rule->registrationNumberRequired,
                'representative_label' => $rule->representativeLabel ?? '',
            ];
        }

        return $rows;
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
                        // THE TERMS: a content page or an external address (a page wins over an address).
                        Radio::make('terms_source')
                            ->label(__('settings.checkout_page.terms_source_label'))
                            ->options(static::sourceOptions())
                            ->inline()
                            ->live(),
                        Select::make('terms_page_id')
                            ->label(__('settings.checkout_page.terms_page_label'))
                            ->options(fn ($get): array => static::pageOptions($get('terms_page_id')))
                            ->placeholder(__('settings.checkout_page.page_placeholder'))
                            ->helperText(__('settings.checkout_page.terms_page_help'))
                            ->visible(fn ($get): bool => $get('terms_source') === 'page')
                            ->live(),
                        TextInput::make('terms_url')
                            ->label(__('settings.checkout_page.terms_url_label'))
                            ->placeholder('https://… / /terms')
                            ->maxLength(2048)
                            ->rules([fn (): Closure => static::linkRule()])
                            ->visible(fn ($get): bool => $get('terms_source') !== 'page')
                            ->live(onBlur: true),
                        // Read-only: nobody types a version. With an address it follows the URL (set to today when the URL changes) and the
                        // button below; with a page it IS the page's revision (p{id}r{revision}) and needs no button.
                        TextInput::make('terms_version')
                            ->label(__('settings.checkout_page.terms_version_label'))
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText(__('settings.checkout_page.terms_version_help'))
                            ->visible(fn ($get): bool => $get('terms_source') !== 'page'),
                        Text::make(fn ($get): string => __('settings.checkout_page.terms_version_page', ['version' => static::pageVersion($get('terms_page_id'))]))
                            ->color('gray')
                            ->size('sm')
                            ->visible(fn ($get): bool => $get('terms_source') === 'page' && filled($get('terms_page_id'))),
                        Actions::make([
                            Action::make('bump_terms_version')
                                ->label(__('settings.checkout_page.terms_updated_button'))
                                ->color('gray')
                                ->action(fn () => $this->bumpTermsVersion()),
                        ])->key('terms-version-actions')->visible(fn ($get): bool => $get('terms_source') !== 'page'),
                        Text::make(__('settings.checkout_page.warning_terms_without_url'))
                            ->color('danger')
                            ->visible(fn ($get): bool => (bool) $get('terms_enabled') && $get('terms_source') !== 'page' && trim((string) $get('terms_url')) === ''),
                        Text::make(__('settings.checkout_page.warning_terms_page_unpublished'))
                            ->color('danger')
                            ->visible(fn ($get): bool => (bool) $get('terms_enabled') && $get('terms_source') === 'page' && ! static::isPublishedPage($get('terms_page_id'))),
                        // THE PRIVACY POLICY: the same choice.
                        Radio::make('privacy_source')
                            ->label(__('settings.checkout_page.privacy_source_label'))
                            ->options(static::sourceOptions())
                            ->inline()
                            ->live(),
                        Select::make('privacy_page_id')
                            ->label(__('settings.checkout_page.privacy_page_label'))
                            ->options(fn ($get): array => static::pageOptions($get('privacy_page_id')))
                            ->placeholder(__('settings.checkout_page.page_placeholder'))
                            ->visible(fn ($get): bool => $get('privacy_source') === 'page')
                            ->live(),
                        TextInput::make('privacy_url')
                            ->label(__('settings.checkout_page.privacy_url_label'))
                            ->placeholder('https://… / /privacy')
                            ->maxLength(2048)
                            ->rules([fn (): Closure => static::linkRule()])
                            ->visible(fn ($get): bool => $get('privacy_source') !== 'page')
                            ->live(onBlur: true),
                        Text::make(__('settings.checkout_page.warning_no_privacy_url'))
                            ->color('warning')
                            ->visible(fn ($get): bool => $get('privacy_source') === 'page'
                                ? ! static::isPublishedPage($get('privacy_page_id'))
                                : trim((string) $get('privacy_url')) === ''),
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
                // THE INVOICE REQUEST (order-context-design.md 9.3): offered unless switched off; the per-country rules are DATA the merchant (and
                // his accountant) type here. No country is pre-filled and no format is asserted as fact.
                Section::make(__('settings.checkout_page.invoice_section'))
                    ->description(__('settings.checkout_page.invoice_help'))
                    ->schema([
                        Toggle::make('invoice_enabled')
                            ->label(__('settings.checkout_page.invoice_enabled_label'))
                            ->helperText(__('settings.checkout_page.invoice_enabled_help'))
                            ->live(),
                        Repeater::make('invoice_rules')
                            ->label(__('settings.checkout_page.invoice_rules_label'))
                            ->addActionLabel(__('settings.checkout_page.invoice_add_rule'))
                            ->schema([
                                Select::make('country')
                                    ->label(__('settings.checkout_page.country_label'))
                                    ->options(fn (): array => CountryNames::forLocale(app(StoreLocale::class)->current()))
                                    ->searchable()
                                    ->required()
                                    ->distinct(),
                                TextInput::make('tax_id_label')
                                    ->label(__('settings.checkout_page.invoice_tax_id_label'))
                                    ->placeholder((string) __('checkout.invoice_labels.tax_id'))
                                    ->maxLength(InvoiceSettings::MAX_LABEL_LENGTH),
                                Toggle::make('tax_id_required_for_company')
                                    ->label(__('settings.checkout_page.invoice_tax_id_required'))
                                    ->default(true),
                                TextInput::make('tax_id_pattern')
                                    ->label(__('settings.checkout_page.invoice_tax_id_pattern'))
                                    ->helperText(__('settings.checkout_page.invoice_pattern_hint'))
                                    ->maxLength(InvoiceSettings::MAX_PATTERN_LENGTH)
                                    ->rules([fn (): Closure => static::patternRule()]),
                                TextInput::make('registration_number_label')
                                    ->label(__('settings.checkout_page.invoice_registration_label'))
                                    ->placeholder((string) __('checkout.invoice_labels.registration_number'))
                                    ->maxLength(InvoiceSettings::MAX_LABEL_LENGTH),
                                Toggle::make('registration_number_required')
                                    ->label(__('settings.checkout_page.invoice_registration_required'))
                                    ->default(false),
                                TextInput::make('representative_label')
                                    ->label(__('settings.checkout_page.invoice_representative_label'))
                                    ->placeholder((string) __('checkout.invoice_labels.representative'))
                                    ->maxLength(InvoiceSettings::MAX_LABEL_LENGTH),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->rules([fn (): Closure => static::invoiceRulesRule()])
                            ->live(),
                    ]),
                // THE PRIVACY WINDOW of the client address (order-context-design.md 7.2).
                Section::make(__('settings.checkout_page.privacy_section'))
                    ->description(__('settings.checkout_page.privacy_help'))
                    ->schema([
                        TextInput::make('ip_full_retention_days')
                            ->label(__('settings.checkout_page.ip_retention_label'))
                            ->helperText(__('settings.checkout_page.ip_retention_help'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(OrderContextSettings::MAX_IP_RETENTION_DAYS)
                            ->default(OrderContextSettings::DEFAULT_IP_RETENTION_DAYS)
                            ->required(),
                    ]),
            ])
            ->statePath('data');
    }

    /** @return array<string, string> */
    public static function sourceOptions(): array
    {
        return ['page' => __('settings.checkout_page.source_page'), 'url' => __('settings.checkout_page.source_url')];
    }

    /**
     * The published pages (id => title) for a select; a page that was chosen and is not published any more stays in the list, marked, so
     * the choice is visible (and the warning explains it) instead of silently vanishing.
     *
     * @return array<int|string, string>
     */
    public static function pageOptions(mixed $selected): array
    {
        $options = app(PageReader::class)->publishedOptions();

        if (filled($selected) && ! isset($options[(int) $selected])) {
            $page = PageModel::query()->find((int) $selected);
            $options[(int) $selected] = $page === null ? '#'.(int) $selected : $page->title.' ('.__('settings.checkout_page.unpublished_marker').')';
        }

        return $options;
    }

    private static function isPublishedPage(mixed $id): bool
    {
        return filled($id) && app(PageReader::class)->publishedById((int) $id) !== null;
    }

    /** The version a terms page will have: its CURRENT revision, as the checkout will record it. */
    private static function pageVersion(mixed $id): string
    {
        $page = filled($id) ? app(PageReader::class)->publishedById((int) $id) : null;

        return $page === null ? '—' : 'p'.$page->id.'r'.$page->revision;
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

    /** A tax-id pattern must COMPILE: the checkout would otherwise judge every company against a rule it cannot run. */
    private static function patternRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && trim($value) !== '' && @preg_match(InvoiceSettings::delimited(trim($value)), '') === false) {
                $fail(__('settings.checkout_page.invoice_pattern_invalid'));
            }
        };
    }

    /** The reader's own validation of the invoice rules, as a form rule: the page cannot accept what InvoiceSettings would refuse. */
    private static function invoiceRulesRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            try {
                InvoiceSettings::normalizeRows(array_values((array) $value));
            } catch (InvalidArgumentException) {
                $fail(__('settings.checkout_page.invoice_rules_invalid'));
            }
        };
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

        $termsIsPage = ($data['terms_source'] ?? 'url') === 'page';
        $privacyIsPage = ($data['privacy_source'] ?? 'url') === 'page';
        $oldTermsUrl = $form->termsUrl() ?? '';

        // A page chosen = its id is stored (and wins); an address chosen = the page id is forgotten. The address of the OTHER mode is left as it
        // is, so switching back finds it. A page mode with no page picked is "no link" (the page warns).
        $termsPageId = $termsIsPage && filled($data['terms_page_id'] ?? null) ? (string) (int) $data['terms_page_id'] : null;
        $privacyPageId = $privacyIsPage && filled($data['privacy_page_id'] ?? null) ? (string) (int) $data['privacy_page_id'] : null;
        $termsPageId === null ? $settings->forget(CheckoutFormSettings::TERMS_PAGE_ID_KEY) : $settings->set(CheckoutFormSettings::TERMS_PAGE_ID_KEY, $termsPageId);
        $privacyPageId === null ? $settings->forget(CheckoutFormSettings::PRIVACY_PAGE_ID_KEY) : $settings->set(CheckoutFormSettings::PRIVACY_PAGE_ID_KEY, $privacyPageId);

        $newTermsUrl = $termsIsPage ? $oldTermsUrl : trim((string) ($data['terms_url'] ?? ''));
        $newPrivacyUrl = $privacyIsPage ? ($form->privacyUrl() ?? '') : trim((string) ($data['privacy_url'] ?? ''));

        $newTermsUrl === '' ? $settings->forget(CheckoutFormSettings::TERMS_URL_KEY) : $settings->set(CheckoutFormSettings::TERMS_URL_KEY, $newTermsUrl);
        $newPrivacyUrl === '' ? $settings->forget(CheckoutFormSettings::PRIVACY_URL_KEY) : $settings->set(CheckoutFormSettings::PRIVACY_URL_KEY, $newPrivacyUrl);

        // A different terms address is a different text: the version becomes today's date by itself (not for a page: its revision is the version).
        if (! $termsIsPage && $newTermsUrl !== '' && $newTermsUrl !== $oldTermsUrl) {
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

        $this->saveInvoiceAndPrivacy($data, $settings);

        Notification::make()
            ->title(__('settings.checkout_page.saved_notification'))
            ->success()
            ->send();
    }

    /**
     * The invoice switch ('0' = off, absent = ON), the per-country rules and the privacy window. The rules are stored with InvoiceSettings'
     * own validation and encoding; their VERSION is bumped by itself, never typed, every time the stored rules CHANGE (the version that
     * accepted an invoice is recorded on its row). Emptying the list forgets the rules but KEEPS the version counter going, so a later rule set
     * can never be mistaken for an older one. A key that holds its default is forgotten, like the region keys.
     *
     * @param  array<string, mixed>  $data
     */
    private function saveInvoiceAndPrivacy(array $data, SiteSettingsRepository $settings): void
    {
        ($data['invoice_enabled'] ?? true) ? $settings->forget(InvoiceSettings::ENABLED_KEY) : $settings->set(InvoiceSettings::ENABLED_KEY, '0');

        $rules = InvoiceSettings::normalizeRows(array_values((array) ($data['invoice_rules'] ?? [])));
        $encoded = $rules === [] ? null : InvoiceSettings::encode($rules);
        $stored = $settings->get(InvoiceSettings::RULES_KEY);
        $stored = $stored === null || trim($stored) === '' ? null : $stored;

        // Compared as DATA, not as text: the same rules in another key order are not a change.
        $changed = ($encoded === null ? [] : json_decode($encoded, true)) != ($stored === null ? [] : json_decode($stored, true));

        if ($changed) {
            $encoded === null ? $settings->forget(InvoiceSettings::RULES_KEY) : $settings->set(InvoiceSettings::RULES_KEY, $encoded);
            $settings->set(InvoiceSettings::RULES_VERSION_KEY, (string) (((int) $settings->get(InvoiceSettings::RULES_VERSION_KEY)) + 1));
        }

        $days = (int) ($data['ip_full_retention_days'] ?? OrderContextSettings::DEFAULT_IP_RETENTION_DAYS);

        $days === OrderContextSettings::DEFAULT_IP_RETENTION_DAYS
            ? $settings->forget(OrderContextSettings::IP_RETENTION_DAYS_KEY)
            : $settings->set(OrderContextSettings::IP_RETENTION_DAYS_KEY, (string) $days);
    }
}
