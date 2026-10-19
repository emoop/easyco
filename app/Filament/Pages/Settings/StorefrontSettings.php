<?php

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Support\HelpLink;
use App\Rules\PlainMultilineText;
use App\Rules\PlainText;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\StorefrontHomeSettings;
use BackedEnum;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * «Витрина» / "Storefront" (stage S3-V): the heading and the short text at the top of the shop's HOME PAGE, which the merchant could not change before
 * (they came from the language file only). Two plain-text keys (StorefrontHomeSettings); EMPTY = the language default, so saving an empty field FORGETS
 * its key. Mirrors CatalogSettings' shape; the permission is the content one (the people who edit the shop's texts), not the settings one.
 */
class StorefrontSettings extends Page
{
    use ServerSideValidation;
    use AuthorizesViaStaffPermission;

    /** @var array<string, mixed> */
    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    public function getTitle(): string
    {
        return __('storefront_settings.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('storefront_settings.navigation_label');
    }

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::ADMIN;
    }

    public static function getNavigationSort(): ?int
    {
        return 34;
    }

    protected static function accessPermission(): ?Permission
    {
        return Permission::CONTENT_MANAGE;
    }

    public static function canAccess(): bool
    {
        return static::staffCanForAction(static::accessPermission());
    }

    public function mount(): void
    {
        $home = app(StorefrontHomeSettings::class);

        $this->form->fill([
            'home_title' => $home->title() ?? '',
            'home_text' => $home->text() ?? '',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('storefront_settings.home_section'))
                    ->description(__('storefront_settings.home_help'))
                    ->schema([
                        HelpLink::component('storefront-home', 'pages'),
                        TextInput::make('home_title')
                            ->label(__('storefront_settings.title_label'))
                            ->maxLength(StorefrontHomeSettings::MAX_TITLE)
                            ->rule(new PlainText())
                            ->live(debounce: 300)
                            ->hint(fn ($state): string => __('storefront_settings.counter', ['count' => mb_strlen((string) $state), 'max' => StorefrontHomeSettings::MAX_TITLE]))
                            ->helperText(__('storefront_settings.title_help')),
                        Textarea::make('home_text')
                            ->label(__('storefront_settings.text_label'))
                            ->rows(4)
                            ->maxLength(StorefrontHomeSettings::MAX_TEXT)
                            ->rule(new PlainMultilineText())
                            ->live(debounce: 300)
                            ->hint(fn ($state): string => __('storefront_settings.counter', ['count' => mb_strlen((string) $state), 'max' => StorefrontHomeSettings::MAX_TEXT]))
                            ->helperText(__('storefront_settings.text_help')),
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
        return static::withoutNativeValidation(Form::make([EmbeddedSchema::make('form')]))
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label(__('storefront_settings.save_label'))
                        ->submit('save'),
                ])->key('form-actions'),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = app(SiteSettingsRepository::class);

        foreach ([StorefrontHomeSettings::TITLE_KEY => 'home_title', StorefrontHomeSettings::TEXT_KEY => 'home_text'] as $key => $field) {
            $value = trim((string) ($data[$field] ?? ''));

            // Empty = the language default: the key is forgotten, never stored as an empty string.
            $value === '' ? $settings->forget($key) : $settings->set($key, $value);
        }

        Notification::make()
            ->title(__('storefront_settings.saved_notification'))
            ->success()
            ->send();
    }
}
