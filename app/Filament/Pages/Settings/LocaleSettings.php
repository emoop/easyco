<?php

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Settings\Contracts\SiteSettingsRepository;
use BackedEnum;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use UnitEnum;

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
 */
class LocaleSettings extends Page
{
    use AuthorizesViaStaffPermission;

    /** @var array<string, mixed> */
    public ?array $data = [];

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-globe-alt';

    protected static string | UnitEnum | null $navigationGroup = 'Settings';

    public function getTitle(): string
    {
        return __('settings.locale.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.locale.navigation_label');
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
        $locale = app(SiteSettingsRepository::class)->get('site.locale') ?? 'bg';

        $this->form->fill(['locale' => $locale]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('locale')
                    ->label(__('settings.locale.field_label'))
                    ->helperText(__('settings.locale.field_help'))
                    ->options([
                        'bg' => 'Български',
                        'en' => 'English',
                    ])
                    ->required(),
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

        app(SiteSettingsRepository::class)->set('site.locale', $data['locale']);

        Notification::make()
            ->title(__('settings.locale.saved_notification'))
            ->success()
            ->send();
    }
}
