<?php

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Mail\BankTransferDetails;
use App\Mail\Iban;
use App\Settings\Contracts\SiteSettingsRepository;
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
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

/**
 * "Payment: bank transfer" (mail-design.md §6.1.1, decided by the owner 2026-10-09): the account details and the
 * free-text instructions shown in the order-confirmation mail for bank-transfer orders. Same shape as
 * LocaleSettings: Permission::SETTINGS_MANAGE, SiteSettingsRepository, one Save.
 *
 * Validation lives in BankTransferDetails::rules() so the page cannot accept what the mail would refuse. The IBAN
 * is stored normalised (no spaces, upper case), the BIC upper case; an empty field forgets its key. An IBAN is
 * never echoed in an error message.
 */
class BankTransferSettings extends Page
{
    use AuthorizesViaStaffPermission;

    /** @var array<string, mixed> */
    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    /** Form field => the site_settings key it is stored under. */
    private const KEYS = [
        'account_holder' => BankTransferDetails::HOLDER,
        'bank_name' => BankTransferDetails::BANK,
        'iban' => BankTransferDetails::IBAN,
        'bic' => BankTransferDetails::BIC,
        'deadline_days' => BankTransferDetails::DEADLINE_DAYS,
        'instructions' => BankTransferDetails::INSTRUCTIONS,
    ];

    public function getTitle(): string
    {
        return __('mail.bank_transfer_page.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('mail.bank_transfer_page.navigation_label');
    }

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::ADMIN;
    }

    public static function getNavigationSort(): ?int
    {
        return 31;
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
        $settings = app(SiteSettingsRepository::class);
        $data = [];

        foreach (self::KEYS as $field => $key) {
            $data[$field] = $settings->get($key);
        }

        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        $rules = BankTransferDetails::rules();

        return $schema
            ->components([
                Text::make(__('mail.bank_transfer_page.help')),
                TextInput::make('account_holder')
                    ->label(__('mail.bank_transfer_page.account_holder'))
                    ->maxLength(BankTransferDetails::MAX_NAME)
                    ->rules($rules['account_holder']),
                TextInput::make('bank_name')
                    ->label(__('mail.bank_transfer_page.bank_name'))
                    ->maxLength(BankTransferDetails::MAX_NAME)
                    ->rules($rules['bank_name']),
                TextInput::make('iban')
                    ->label(__('mail.bank_transfer_page.iban'))
                    ->helperText(__('mail.bank_transfer_page.iban_help'))
                    ->maxLength(60)
                    ->rules($rules['iban']),
                TextInput::make('bic')
                    ->label(__('mail.bank_transfer_page.bic'))
                    ->maxLength(20)
                    ->rules($rules['bic']),
                TextInput::make('deadline_days')
                    ->label(__('mail.bank_transfer_page.deadline_days'))
                    ->helperText(__('mail.bank_transfer_page.deadline_help'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(60)
                    ->rules($rules['deadline_days']),
                Textarea::make('instructions')
                    ->label(__('mail.bank_transfer_page.instructions'))
                    ->helperText(__('mail.bank_transfer_page.instructions_help'))
                    ->rows(5)
                    ->maxLength(BankTransferDetails::MAX_INSTRUCTIONS)
                    ->rules($rules['instructions']),
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
                        ->label(__('mail.bank_transfer_page.save_label'))
                        ->submit('save'),
                ])->key('form-actions'),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = app(SiteSettingsRepository::class);

        foreach (self::KEYS as $field => $key) {
            $value = $data[$field] ?? null;
            $value = is_scalar($value) ? trim((string) $value) : '';

            $value = match ($field) {
                'iban' => Iban::normalize($value),
                'bic' => strtoupper((string) preg_replace('/\s+/', '', $value)),
                'instructions' => str_replace(["\r\n", "\r"], "\n", $value),
                default => $value,
            };

            if ($value === '') {
                $settings->forget($key);
            } else {
                $settings->set($key, $value);
            }
        }

        Notification::make()
            ->title(__('mail.bank_transfer_page.saved_notification'))
            ->success()
            ->send();
    }
}
