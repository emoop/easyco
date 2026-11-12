<?php

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Design\InfoHint;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use BackedEnum;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Site Settings' first real Catalog-specific consumer
 * (admin-panel-design.md §13.4) — whether ProductResource's
 * product_group_id field is required. Mirrors LocaleSettings' exact
 * shape (form/content/save() structure, SiteSettingsRepository usage,
 * the local canAccess() override).
 *
 * A NEW, separate page from LocaleSettings, not folded into it —
 * this setting is Catalog-specific (only affects ProductResource's
 * form), while locale is genuinely site-wide (admin + storefront).
 * Lives under NavigationGroup::CATALOG, not Admin — a merchant
 * configuring product-group requirements looks in Catalog, not a
 * generic Admin area.
 */
class CatalogSettings extends Page
{
    use ServerSideValidation;
    use AuthorizesViaStaffPermission;

    /** @var array<string, mixed> */
    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-vertical';

    public function getTitle(): string
    {
        return __('settings.catalog.title');
    }

    /** See ProductResource::getNavigationGroup()'s docblock — sort 80 places this after Season (70) within the Catalog group. */
    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::CATALOG;
    }

    public static function getNavigationSort(): ?int
    {
        return 80;
    }

    /**
     * Reuses navigation.groups.catalog rather than a dedicated
     * settings.catalog.navigation_label key — the sidebar item sits
     * right under the "Каталог"/"Catalog" group header it already
     * names, so this label doubles as a plain, unambiguous "Catalog
     * settings" reading in context.
     */
    public static function getNavigationLabel(): string
    {
        return __('navigation.groups.catalog');
    }

    protected static function accessPermission(): ?Permission
    {
        return Permission::SETTINGS_MANAGE;
    }

    /**
     * Defined locally, not on the shared trait — see
     * AuthorizesViaStaffPermission's own class docblock for why: adding
     * canAccess() there would silently override Resource's inherited,
     * working canAccess() with one keyed to accessPermission(), which
     * no Resource ever declares. See LocaleSettings::canAccess()'s
     * identical docblock for the full history.
     */
    public static function canAccess(): bool
    {
        return static::staffCanForAction(static::accessPermission());
    }

    public function mount(): void
    {
        $settings = app(SiteSettingsRepository::class);

        $this->form->fill([
            // P6c: ON unless a stored `0` (the default also for a shop that never saved this page)
            'hide_sold_out' => $settings->get(AppearanceSettings::HIDE_SOLD_OUT_KEY) !== '0',
            'product_group_required' => ($settings->get('catalog.product_group_required') ?? '0') === '1',
            // Default TRUE for all four — see ProductResource's own
            // seasonFieldEnabled()/brandFieldEnabled()/tagsFieldEnabled()/
            // productGroupFieldEnabled() docblock: a missing key means
            // "never visited this page yet", which must keep today's
            // exact behavior (every field shown), not silently hide one.
            'season_field_enabled' => $settings->get('catalog.season_field_enabled') !== '0',
            // P6e-1: «Колекции» — OFF unless the stored value is the literal '1' (the opposite default)
            'collections_enabled' => $settings->get('catalog.collections_enabled') === '1',
            'brand_field_enabled' => $settings->get('catalog.brand_field_enabled') !== '0',
            'tags_field_enabled' => $settings->get('catalog.tags_field_enabled') !== '0',
            'product_group_field_enabled' => $settings->get('catalog.product_group_field_enabled') !== '0',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make('hide_sold_out')
                    ->label(__('settings.catalog.hide_sold_out_label'))
                    ->afterLabel(InfoHint::make(__('settings.catalog.hide_sold_out_hint'), 'hide_sold_out'))
                    ->default(true),
                Toggle::make('season_field_enabled')
                    ->label(__('settings.catalog.season_enabled_label'))
                    ->helperText(__('settings.catalog.season_enabled_help'))
                    ->default(true),
                Toggle::make('collections_enabled')
                    ->label(__('settings.catalog.collections_enabled_label'))
                    ->afterLabel(InfoHint::make(__('settings.catalog.collections_enabled_hint'), 'collections_enabled'))
                    ->default(false),
                Toggle::make('brand_field_enabled')
                    ->label(__('settings.catalog.brand_enabled_label'))
                    ->helperText(__('settings.catalog.brand_enabled_help'))
                    ->default(true),
                Toggle::make('tags_field_enabled')
                    ->label(__('settings.catalog.tags_enabled_label'))
                    ->helperText(__('settings.catalog.tags_enabled_help'))
                    ->default(true),
                // ->live(): the same Get()-driven reactive-visibility
                // mechanism LocaleSettings' own activity_log_enabled ->
                // activity_log_retention_months pair already establishes
                // — "required" only makes sense, and is only shown, while
                // the field itself is visible at all.
                Toggle::make('product_group_field_enabled')
                    ->label(__('settings.catalog.group_enabled_label'))
                    ->helperText(__('settings.catalog.group_enabled_help'))
                    ->live()
                    ->default(true),
                Toggle::make('product_group_required')
                    ->label(__('settings.catalog.field_label'))
                    ->helperText(__('settings.catalog.field_help'))
                    ->visible(fn (Get $get): bool => (bool) $get('product_group_field_enabled'))
                    ->default(false),
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
                        ->label(__('settings.catalog.save_label'))
                        ->submit('save'),
                ])->key('form-actions'),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = app(SiteSettingsRepository::class);

        // P6c: the list queries read this row themselves (a sub-condition of the same statement), so the change is live at once
        $settings->set(AppearanceSettings::HIDE_SOLD_OUT_KEY, $data['hide_sold_out'] ? '1' : '0');

        $settings->set('catalog.season_field_enabled', $data['season_field_enabled'] ? '1' : '0');
        $settings->set('catalog.collections_enabled', ($data['collections_enabled'] ?? false) ? '1' : '0');
        // P6e-2: the storefront's filter list is cached WITH the collections only while the switch is on: forget it, so the new state shows at once
        \App\Storefront\Filters\FilterCatalog::forget();
        $settings->set('catalog.brand_field_enabled', $data['brand_field_enabled'] ? '1' : '0');
        $settings->set('catalog.tags_field_enabled', $data['tags_field_enabled'] ? '1' : '0');
        $settings->set('catalog.product_group_field_enabled', $data['product_group_field_enabled'] ? '1' : '0');

        // product_group_required is hidden (->visible() false) whenever
        // product_group_field_enabled is off — a hidden field does not
        // dehydrate into getState() (the same documented gap
        // LocaleSettings' own activity_log_retention_months already
        // hit), so the key is genuinely absent then, not just falsy.
        // Falls back to '0': the field being off makes "required" moot,
        // and this keeps the stored value consistent with what the
        // (now-hidden) toggle last showed if the merchant re-enables
        // the field group later.
        $settings->set(
            'catalog.product_group_required',
            ($data['product_group_required'] ?? false) ? '1' : '0'
        );

        Notification::make()
            ->title(__('settings.catalog.saved_notification'))
            ->success()
            ->send();
    }
}
