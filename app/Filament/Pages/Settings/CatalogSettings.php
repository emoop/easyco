<?php

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
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
        $required = app(SiteSettingsRepository::class)->get('catalog.product_group_required') ?? '0';

        $this->form->fill(['product_group_required' => $required === '1']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make('product_group_required')
                    ->label(__('settings.catalog.field_label'))
                    ->helperText(__('settings.catalog.field_help'))
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
        return Form::make([EmbeddedSchema::make('form')])
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

        app(SiteSettingsRepository::class)->set(
            'catalog.product_group_required',
            $data['product_group_required'] ? '1' : '0'
        );

        Notification::make()
            ->title(__('settings.catalog.saved_notification'))
            ->success()
            ->send();
    }
}
