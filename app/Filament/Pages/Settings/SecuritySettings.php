<?php

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\Design\InfoHint;
use App\Filament\NavigationGroup;
use App\Security\BotChallenge\BotChallenge;
use App\Security\BotChallenge\BotChallengeSwitch;
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

/**
 * P8d: «Сигурност». One field today: «Усилена защита от ботове» (off | filters | misses), stored as `security.bot_challenge` and pushed to the cache layer by the
 * BotChallengeSwitch. Visible and writable only for the strongest existing permission, SETTINGS_MANAGE (Administrator only: the `Permission` docblock); the page
 * checks it on open AND on save, so a hand-made Livewire call from a lesser role is a 403. No existing settings page fits (catalog, checkout, mail, locale, appearance,
 * bank transfer), so this is the small new page the package allows.
 */
class SecuritySettings extends Page
{
    use AuthorizesViaStaffPermission;
    use ServerSideValidation;

    /** @var array<string, mixed> */
    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    public function getTitle(): string
    {
        return __('security.page.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('security.page.navigation_label');
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
        return Permission::SETTINGS_MANAGE;
    }

    public static function canAccess(): bool
    {
        return static::staffCanForAction(static::accessPermission());
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill(['bot_challenge' => app(BotChallenge::class)->current()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('bot_challenge')
                    ->label(__('security.page.bot_challenge_label'))
                    ->afterLabel(InfoHint::make(__('security.page.bot_challenge_hint'), 'bot_challenge'))
                    ->options([
                        BotChallenge::OFF => __('security.page.bot_challenge_off'),
                        BotChallenge::FILTERS => __('security.page.bot_challenge_filters'),
                        BotChallenge::MISSES => __('security.page.bot_challenge_misses'),
                    ])
                    ->in(BotChallenge::MODES)
                    ->native(false)
                    ->selectablePlaceholder(false)
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
        return static::withoutNativeValidation(Form::make([EmbeddedSchema::make('form')]))
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label(__('security.page.save_label'))
                        ->submit('save'),
                ])->key('form-actions'),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $mode = (string) ($this->form->getState()['bot_challenge'] ?? BotChallenge::OFF);
        $mode = BotChallenge::valid($mode) ? $mode : BotChallenge::OFF;

        app(BotChallenge::class)->set($mode);
        app(BotChallengeSwitch::class)->push($mode);

        Notification::make()->title(__('security.page.saved_notification'))->success()->send();
    }
}
