<?php

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Settings\Contracts\SiteSettingsRepository;
use BackedEnum;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Site Settings' first real admin-panel consumer
 * (site-settings-design.md) — a merchant-changeable storefront locale.
 * Gated by Permission::SETTINGS_MANAGE, that permission's own first
 * real consumer too (its docblock: "site settings, including payment
 * configuration").
 *
 * Grouped under a "Settings" navigation section deliberately — this is
 * the first of several settings screens site-settings-design.md §1
 * already names as confirmed future consumers (Hero Slider toggle,
 * logo/favicon, product image aspect ratio, checkout's phone-call
 * field). Only the locale field itself is built now; the navigation
 * group exists so those can sit alongside it later without restructuring
 * this page.
 *
 * NOW A TABBED "Settings" PAGE — Tab 1 is the original Locale content,
 * unchanged; Tab 2 adds Activity Log enable/retention controls
 * (admin.activity_log_enabled / admin.activity_log_retention_months,
 * a global admin.* setting — deliberately NOT catalog.*, per the
 * domain owner's own explicit split from CatalogSettings, which stays
 * a separate page/prefix). The class stays named LocaleSettings (not
 * renamed) — this task's own instruction is to restructure this exact
 * page, not rename/relocate it.
 */
class LocaleSettings extends Page
{
    use AuthorizesViaStaffPermission;

    /** @var array<string, mixed> */
    public ?array $data = [];

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-globe-alt';

    /**
     * Generalized to the same "Settings" label the sidebar already
     * uses (settings.navigation_label) — this page now covers two
     * unrelated tabs, not just locale, so a page-specific "Език"
     * title would undersell what Tab 2 holds.
     */
    public function getTitle(): string
    {
        return __('settings.navigation_label');
    }

    /**
     * Navigation grouping — admin-panel-design.md's stated top-level
     * structure (this task). See RoleResource::getNavigationGroup()'s
     * docblock for the group/sort reasoning; sort 30 places Settings
     * after Role (10) and Staff (20) within the Admin group.
     */
    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::ADMIN;
    }

    public static function getNavigationSort(): ?int
    {
        return 30;
    }

    /**
     * Same key as getTitle() now reads (settings.navigation_label,
     * "Настройки"/"Settings") — both generalized together once this
     * page stopped being locale-only; settings.locale.title still
     * exists, now only as this page's Tab 1 label.
     */
    public static function getNavigationLabel(): string
    {
        return __('settings.navigation_label');
    }

    protected static function accessPermission(): ?Permission
    {
        return Permission::SETTINGS_MANAGE;
    }

    /**
     * Defined locally, not on the shared trait — see
     * AuthorizesViaStaffPermission's own class docblock for why: adding
     * canAccess() there would silently override Resource's inherited,
     * working canAccess() (RoleResource/StaffResource use this same
     * trait) with one keyed to accessPermission(), which no Resource
     * ever declares, permanently returning false for both — a real
     * regression discovered and reverted during this task.
     */
    public static function canAccess(): bool
    {
        return static::staffCanForAction(static::accessPermission());
    }

    public function mount(): void
    {
        $settings = app(SiteSettingsRepository::class);

        $locale = $settings->get('site.locale') ?? 'bg';
        $activityLogEnabled = $settings->get('admin.activity_log_enabled') === '1';
        $activityLogRetentionMonths = (int) ($settings->get('admin.activity_log_retention_months') ?? 12);

        $this->form->fill([
            'locale' => $locale,
            'activity_log_enabled' => $activityLogEnabled,
            'activity_log_retention_months' => $activityLogRetentionMonths,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Settings')
                    ->tabs([
                        Tab::make(__('settings.locale.title'))
                            ->schema([
                                Select::make('locale')
                                    ->label(__('settings.locale.field_label'))
                                    ->helperText(__('settings.locale.field_help'))
                                    ->options([
                                        'bg' => 'Български',
                                        'en' => 'English',
                                    ])
                                    ->required(),
                            ]),
                        Tab::make(__('settings.activity_log.tab_label'))
                            ->schema([
                                Toggle::make('activity_log_enabled')
                                    ->label(__('settings.activity_log.enabled_label'))
                                    ->helperText(__('settings.activity_log.enabled_help'))
                                    // ->live(): the real, confirmed
                                    // mechanism (Filament\Schemas\
                                    // Components\Utilities\Get, typed-
                                    // injected into the retention
                                    // Select's own ->visible() closure
                                    // below) for one field's visibility
                                    // to react to another field's state
                                    // without a full form reload.
                                    ->live()
                                    ->default(false),
                                Select::make('activity_log_retention_months')
                                    ->label(__('settings.activity_log.retention_label'))
                                    ->helperText(__('settings.activity_log.retention_help'))
                                    ->options([
                                        6 => __('settings.activity_log.retention_options.6'),
                                        12 => __('settings.activity_log.retention_options.12'),
                                        18 => __('settings.activity_log.retention_options.18'),
                                    ])
                                    ->default(12)
                                    ->visible(fn (Get $get): bool => (bool) $get('activity_log_enabled'))
                                    ->required(fn (Get $get): bool => (bool) $get('activity_log_enabled')),
                            ]),
                    ]),
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
                        ->label(__('settings.locale.save_label'))
                        ->submit('save'),
                ])->key('form-actions'),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        // activity_log_retention_months is a REAL, confirmed gap found
        // while testing: a component hidden by ->visible() is simply
        // not dehydrated into getState() at all (unlike a merely
        // ->disabled() one) — so whenever activity_log_enabled is off,
        // that key is genuinely absent from $data, not just falsy.
        // Falling back to the field's own default (12) keeps save()
        // correct in that state rather than assuming the key exists.
        $settings = app(SiteSettingsRepository::class);
        $settings->set('site.locale', $data['locale']);
        $settings->set('admin.activity_log_enabled', $data['activity_log_enabled'] ? '1' : '0');
        $settings->set('admin.activity_log_retention_months', (string) ($data['activity_log_retention_months'] ?? 12));

        Notification::make()
            ->title(__('settings.locale.saved_notification'))
            ->success()
            ->send();
    }
}
