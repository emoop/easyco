<?php

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\Design\InfoHint;
use App\Filament\NavigationGroup;
use App\Privacy\PrivacySettings;
use App\Privacy\RetentionPreview;
use BackedEnum;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ACC-4e-2 (acc-4-gdpr-design.md section 7): «Поверителност: настройки». `/admin/privacy-settings`, in the «Поверителност» group (second, after the forget log), PRIVACY_MANAGE: checked on open, on every
 * Livewire request and again in the save. The three settings are the ones the commands read: `privacy.order_retention_years` (OrderAnonymiser through OrderRetention), `privacy.mail_log_retention_days`
 * (`mail:prune-log`) and `privacy.manual_steps`: all through PrivacySettings, the one reader and writer.
 *
 * LOWERING THE ORDER RETENTION IS DESTRUCTIVE: the next nightly `privacy:anonymise-orders` anonymises older orders for good. When the new value is LOWER than the saved one the save asks for a confirmation
 * that states how many orders the next run would anonymise (RetentionPreview, the command's own eligibility and OrderRetention's cutoff); raising it asks nothing. The save is a PROTECTED method that only
 * the confirmed action calls: a hand-made Livewire call cannot skip the confirmation.
 *
 * The steps are free labels with a stable key made when a step is added (PrivacySettings::newStepKey), kept when its label is edited: a ticked step in the log refers to the key.
 */
class PrivacySettingsPage extends Page
{
    use AuthorizesViaStaffPermission;
    use ServerSideValidation;

    /** @var array<string, mixed> */
    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $slug = 'privacy-settings';

    public function getTitle(): string
    {
        return __('privacy_admin.settings.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('privacy_admin.settings.navigation_label');
    }


    /** The full page title as the browser tooltip: a long label is cut with an ellipsis by the sidebar (the link keeps its text as its accessible name). */
    public static function getNavigationItems(): array
    {
        return array_map(
            static fn (\Filament\Navigation\NavigationItem $item): \Filament\Navigation\NavigationItem => $item->extraAttributes(['title' => __('privacy_admin.settings.title')]),
            parent::getNavigationItems(),
        );
    }

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::PRIVACY;
    }

    /** Second in the «Поверителност» group, after the forget log (10). */
    public static function getNavigationSort(): ?int
    {
        return 20;
    }

    protected static function accessPermission(): ?Permission
    {
        return Permission::PRIVACY_MANAGE;
    }

    public static function canAccess(): bool
    {
        return static::staffCanForAction(static::accessPermission());
    }

    public function boot(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $settings = app(PrivacySettings::class);

        $this->form->fill([
            'retention_years' => $settings->orderRetentionYears(),
            'mail_log_days' => $settings->mailLogRetentionDays(),
            'steps' => array_map(static fn (array $step): array => ['key' => $step['key'], 'label' => $step['label']], $settings->manualStepList()),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('retention_years')
                    ->label(__('privacy_admin.settings.retention_years'))
                    ->afterLabel(InfoHint::make(__('privacy_admin.settings.retention_hint'), 'retention_years'))
                    ->numeric()->integer()->minValue(1)->maxValue(30)->required(),
                TextInput::make('mail_log_days')
                    ->label(__('privacy_admin.settings.mail_log_days'))
                    ->afterLabel(InfoHint::make(__('privacy_admin.settings.mail_log_hint'), 'mail_log_days'))
                    ->numeric()->integer()->minValue(PrivacySettings::MIN_MAIL_LOG_RETENTION_DAYS)->maxValue(3650)->required(),
                Repeater::make('steps')
                    ->label(__('privacy_admin.settings.steps'))
                    ->afterLabel(InfoHint::make(__('privacy_admin.settings.steps_hint'), 'steps'))
                    ->schema([
                        Hidden::make('key'),
                        TextInput::make('label')
                            ->label(__('privacy_admin.settings.step_label'))
                            ->required()
                            ->maxLength(PrivacySettings::MAX_MANUAL_STEP_LENGTH)
                            ->rules([static fn (): \Closure => static function (string $attribute, mixed $value, \Closure $fail): void {
                                is_string($value) && preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1 && $fail(__('validation.plain_text'));
                            }]),
                    ])
                    ->maxItems(PrivacySettings::MAX_MANUAL_STEPS)
                    ->defaultItems(0)
                    ->addActionLabel(__('privacy_admin.settings.add_step'))
                    ->reorderable(false)
                    ->columnSpanFull(),
            ])
            ->columns(2)
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
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label(__('privacy_admin.settings.save'))
                        ->requiresConfirmation(fn (): bool => $this->isLowering())
                        // the modal exists ONLY while the retention is being lowered: a heading would open one for a plain save too
                        ->modalHeading(fn (): ?string => $this->isLowering() ? (string) __('privacy_admin.settings.confirm_heading') : null)
                        ->modalDescription(fn (): ?string => $this->isLowering() ? $this->confirmText() : null)
                        ->modalSubmitActionLabel(fn (): ?string => $this->isLowering() ? (string) __('privacy_admin.settings.confirm_submit') : null)
                        ->action(fn () => $this->persist()),
                ])->key('form-actions'),
            ]);
    }

    /** Is the typed retention LOWER than the saved one? (the form's own value, read raw: validation comes with the save) */
    protected function isLowering(): bool
    {
        $typed = $this->data['retention_years'] ?? null;

        return is_numeric($typed) && (int) $typed < app(PrivacySettings::class)->orderRetentionYears();
    }

    protected function confirmText(): string
    {
        $count = app(RetentionPreview::class)->count(max(1, (int) ($this->data['retention_years'] ?? 1)));

        return (string) ($count > 0 ? __('privacy_admin.settings.confirm_text', ['count' => $count]) : __('privacy_admin.settings.confirm_text_none'));
    }

    /** PROTECTED: only the (confirmed) action calls it. */
    protected function persist(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();
        $settings = app(PrivacySettings::class);

        try {
            DB::transaction(function () use ($settings, $state): void {
                $settings->saveOrderRetentionYears((int) $state['retention_years']);
                $settings->saveMailLogRetentionDays((int) $state['mail_log_days']);
                $settings->saveManualStepList(array_values(array_map(
                    static fn (array $step): array => ['key' => ($step['key'] ?? null) ?: null, 'label' => (string) ($step['label'] ?? '')],
                    $state['steps'] ?? [],
                )));
            });
        } catch (InvalidArgumentException) {
            Notification::make()->title(__('privacy_admin.settings.invalid'))->danger()->send();

            return;
        }

        // the form now holds the keys the new steps were given
        $this->mount();
        Notification::make()->title(__('privacy_admin.settings.saved'))->success()->send();
    }
}
