<?php

namespace App\Filament\Pages\Settings;

use App\Couriers\CourierDirectory;
use App\Couriers\CourierSecretUnreadableException;
use App\Couriers\CourierSettings;
use App\Couriers\CourierSyncRun;
use App\Couriers\Settlements\SettlementBuilder;
use App\Couriers\Settlements\SettlementReport;
use App\Couriers\Settlements\SettlementSyncer;
use App\Couriers\SyncCourierPointsJob;
use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Services\ActivityLogger;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\StoreTimezone;
use BackedEnum;
use DateTimeImmutable;
use EasyCo\Shipping\Carrier\CarrierRegistry;
use EasyCo\Shipping\Carrier\CourierEnvironment;
use EasyCo\Shipping\Carrier\CourierField;
use EasyCo\Shipping\Contracts\CourierDescriptor;
use EasyCo\Shipping\Contracts\CourierHttp;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Facades\Filament;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;

/**
 * «Куриери» (courier-integration-design.md §5, stage COUR-1a): one section per INSTALLED courier. Permission settings_manage;
 * «Обнови сега» additionally needs shipping_manage. Same shape as MailSettings (SiteSettingsRepository, one Save).
 *
 * NOTHING HERE IS ON A CUSTOMER PATH: the page only writes `couriers.*` settings and starts two staff actions.
 *
 * SECRETS ARE WRITE-ONLY, exactly the MailSettings pattern (mail-design.md §2.1): stored with Crypt::encryptString, never filled
 * into the form, never rendered, dropped from the Livewire state in dehydrate() so a typed one never rides in a snapshot; the
 * form shows «запазена» / «нечетима — въведете отново» from CourierSettings::secretState(); the journal records THAT a key changed,
 * never a value. Test and production credentials are separate keys and separate inputs.
 *
 * «Провери връзката» uses the SAVED settings and shows OK or a CLOSED error code's text. A courier's response body never
 * reaches this class: the descriptor returns a ConnectionCheck, and an unknown code is shown as «неизвестна грешка».
 *
 * NOT HERE YET (COUR-3a): the sender profile and the defaults (label format, Q2 switch…). No empty section stands in for them.
 */
class CouriersSettings extends Page
{
    use AuthorizesViaStaffPermission;
    use ServerSideValidation;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** The last connection check of each courier: closed code only. @var array<string, array{ok: bool, code: ?string}> */
    public array $connection = [];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    /** @var array<string, string> request-lifetime memo of secretState() */
    private array $secretStates = [];

    public function getTitle(): string
    {
        return __('couriers.page.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('couriers.page.navigation_label');
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
        return Permission::SETTINGS_MANAGE;
    }

    public static function canAccess(): bool
    {
        return static::staffCanForAction(static::accessPermission());
    }

    private function guard(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function mount(): void
    {
        $this->guard();

        $settings = app(SiteSettingsRepository::class);
        $vault = app(CourierSettings::class);
        $data = [];

        foreach (app(CourierDirectory::class)->installed() as $descriptor) {
            $code = $descriptor->code();
            $data[$code] = [
                'enabled' => $vault->isEnabled($code),
                'environment' => $vault->environment($code)->value,
                'map_enabled' => $vault->mapEnabled($code),
            ];

            foreach (CourierEnvironment::cases() as $environment) {
                foreach ($descriptor->fields() as $field) {
                    // A secret is NEVER filled into the form.
                    $data[$code][$environment->value][$field->name] = $field->isSecret()
                        ? null
                        : ($settings->get($vault->fieldKey($code, $field, $environment)) ?? $field->defaultFor($environment));
                }
            }
        }

        $this->form->fill($data);
    }

    /** Livewire lifecycle hook: runs before the snapshot is built, so a typed secret never rides in it. */
    public function dehydrate(): void
    {
        if (! is_array($this->data)) {
            return;
        }

        foreach (app(CourierDirectory::class)->installed() as $descriptor) {
            foreach (CourierEnvironment::cases() as $environment) {
                foreach ($descriptor->fields() as $field) {
                    if ($field->isSecret() && isset($this->data[$descriptor->code()][$environment->value]) && is_array($this->data[$descriptor->code()][$environment->value])) {
                        $this->data[$descriptor->code()][$environment->value][$field->name] = null;
                    }
                }
            }
        }
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];

        foreach (app(CourierDirectory::class)->installed() as $descriptor) {
            $sections[] = $this->courierSection($descriptor);
        }

        if ($sections === []) {
            $sections[] = Text::make(__('couriers.page.no_couriers'));
        }

        return $schema
            ->components([Text::make(__('couriers.page.intro')), ...$sections])
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
                        ->label(__('couriers.page.save_label'))
                        ->submit('save'),
                ])->key('form-actions'),
            ]);
    }

    private function courierSection(CourierDescriptor $descriptor): Section
    {
        $code = $descriptor->code();
        $name = app(CarrierRegistry::class)->find($code)?->name ?? $code;

        $components = [
            Toggle::make("{$code}.enabled")
                ->label(__('couriers.common.enabled'))
                ->helperText(__('couriers.common.enabled_help')),
            Select::make("{$code}.environment")
                ->label(__('couriers.common.environment'))
                ->helperText(__('couriers.common.environment_help'))
                ->options([
                    CourierEnvironment::TEST->value => __('couriers.common.env_test'),
                    CourierEnvironment::PRODUCTION->value => __('couriers.common.env_production'),
                ])
                ->native(false)
                ->selectablePlaceholder(false),
            Toggle::make("{$code}.map_enabled")
                ->label(__('couriers.common.map_enabled'))
                ->helperText(__('couriers.common.map_enabled_help')),
        ];

        foreach (CourierEnvironment::cases() as $environment) {
            $components[] = Section::make(__('couriers.common.section_'.$environment->value))
                ->compact()
                ->schema(array_map(fn (CourierField $field): Component => $this->fieldComponent($code, $environment, $field), $descriptor->fields()));
        }

        $components[] = Actions::make([
            Action::make("test_{$code}")
                ->label(__('couriers.test.button'))
                ->action(fn () => $this->checkConnection($code)),
        ])->key("test-actions-{$code}");
        $components[] = Text::make(__('couriers.test.help'));
        $components[] = Text::make(fn (): HtmlString => $this->connectionHtml($code))
            ->visible(fn (): bool => isset($this->connection[$code]));

        $components[] = Text::make(fn (): HtmlString => $this->lastRunHtml($code, $name));
        $components[] = Text::make(fn (): HtmlString => $this->reportHtml($code));
        $components[] = Actions::make([
            Action::make("sync_{$code}")
                ->label(__('couriers.sync.button'))
                ->visible(fn (): bool => static::staffCanForAction(Permission::SHIPPING_MANAGE))
                ->action(fn () => $this->syncNow($code)),
        ])->key("sync-actions-{$code}");

        return Section::make($name)->schema($components);
    }

    private function fieldComponent(string $code, CourierEnvironment $environment, CourierField $field): Component
    {
        $path = "{$code}.{$environment->value}.{$field->name}";
        $label = __('couriers.'.$field->label);

        if ($field->isSecret()) {
            return TextInput::make($path)
                ->label($label)
                ->password()
                ->autocomplete('new-password')
                ->placeholder(fn (): ?string => $this->secretState($code, $field, $environment) === 'saved' ? __('couriers.secret.saved_placeholder') : null)
                ->helperText(fn (): string => __('couriers.secret.help_'.$this->secretState($code, $field, $environment)))
                ->maxLength($field->max)
                ->rules(['nullable', 'not_regex:/[\x00-\x1F\x7F]/'])
                ->validationMessages(['not_regex' => __('couriers.validation.secret_plain')]);
        }

        $input = TextInput::make($path)->label($label)->maxLength($field->max);

        if ($field->kind === CourierField::HOST) {
            $input->helperText(__('couriers.'.$field->label.'_help'))
                ->rules([
                    static fn (): \Closure => static function (string $attribute, mixed $value, \Closure $fail) use ($field): void {
                        if (is_string($value) && trim($value) !== '' && $field->cleanHost($value) === null) {
                            $fail(__('couriers.validation.api_host'));
                        }
                    },
                ]);
        }

        return $input;
    }

    public function save(): void
    {
        $this->guard();

        $data = $this->form->getState();
        $settings = app(SiteSettingsRepository::class);
        $vault = app(CourierSettings::class);
        $journal = app(ActivityLogger::class);
        $firstSyncs = [];

        foreach (app(CourierDirectory::class)->installed() as $descriptor) {
            $code = $descriptor->code();
            $input = is_array($data[$code] ?? null) ? $data[$code] : [];
            $changed = [];

            $write = function (string $key, ?string $value, bool $showValue = false) use ($settings, $code, $journal): void {
                $old = $settings->get($key);
                $value = $value === null || $value === '' ? null : $value;

                if ($old === $value) {
                    return;
                }

                $value === null ? $settings->forget($key) : $settings->set($key, $value);
                // The fact that it changed; a value only for the three switches (never a credential).
                $journal->logFieldChanged('courier_settings', $code, $key, $showValue ? $old : null, $showValue ? ($value ?? 'off') : 'changed');
            };

            $wasEnabled = $vault->isEnabled($code);
            $enabled = ! empty($input['enabled']);

            $write($vault->key($code, CourierSettings::ENABLED), $enabled ? '1' : null, true);

            if ($enabled && ! $wasEnabled) {
                // The clock for «never synced yet» (CourierSyncStaleSource).
                $settings->set($vault->key($code, CourierSettings::ENABLED_AT), (new DateTimeImmutable)->format('Y-m-d H:i:s'));
                // First time on: its settlements and points are fetched at once (courier-integration-design.md §3.4), not tonight.
                if (! app(SettlementSyncer::class)->hasRun($code)) {
                    $firstSyncs[] = $code;
                }
            }

            $environment = CourierEnvironment::fromStored(is_scalar($input['environment'] ?? null) ? (string) $input['environment'] : null);
            $write($vault->key($code, CourierSettings::ENVIRONMENT), $environment->value, true);
            $write($vault->key($code, CourierSettings::MAP_ENABLED), ! empty($input['map_enabled']) ? '1' : null, true);

            foreach (CourierEnvironment::cases() as $env) {
                foreach ($descriptor->fields() as $field) {
                    $value = $input[$env->value][$field->name] ?? null;
                    $value = is_scalar($value) ? trim((string) $value) : '';
                    $key = $vault->fieldKey($code, $field, $env);

                    if ($field->isSecret()) {
                        // Write-only: empty keeps the stored value; a typed one replaces it, encrypted. The raw value goes nowhere else.
                        if ($value !== '') {
                            $settings->set($key, Crypt::encryptString($value));
                            $journal->logFieldChanged('courier_settings', $code, $key, null, 'changed');
                        }

                        continue;
                    }

                    if ($field->kind === CourierField::HOST && $value !== '') {
                        $value = (string) $field->cleanHost($value);
                    }

                    $write($key, $value);
                }
            }
        }

        foreach ($firstSyncs as $code) {
            SyncCourierPointsJob::dispatch($code, 'manual', true);
        }

        $this->dehydrate();
        $this->secretStates = [];

        Notification::make()->title(__('couriers.page.saved_notification'))->success()->send();
    }

    /** «Провери връзката»: the SAVED settings of the courier's current environment; the result is a closed code. */
    public function checkConnection(string $code): void
    {
        $this->guard();

        $descriptor = app(CourierDirectory::class)->descriptor($code);
        abort_if($descriptor === null, 404);

        $key = 'courier-check:'.(string) Filament::auth()->id();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->connection[$code] = ['ok' => false, 'code' => 'too_many_checks'];

            return;
        }

        RateLimiter::hit($key, 60);

        if (! app(CourierDirectory::class)->isEnabled($code)) {
            $this->connection[$code] = ['ok' => false, 'code' => 'not_enabled'];

            return;
        }

        try {
            $credentials = app(CourierSettings::class)->credentials($descriptor);
        } catch (CourierSecretUnreadableException) {
            $this->connection[$code] = ['ok' => false, 'code' => 'credentials_unreadable'];

            return;
        }

        $result = $descriptor->checkConnection(app(CourierHttp::class), $credentials);

        $this->connection[$code] = ['ok' => $result->ok, 'code' => $result->code];
    }

    /** «Обнови сега»: the same job as the night run, for one courier; at most once per 5 minutes per courier. */
    public function syncNow(string $code): void
    {
        $this->guard();
        abort_unless(static::staffCanForAction(Permission::SHIPPING_MANAGE), 403);

        $directory = app(CourierDirectory::class);

        if (! $directory->isEnabled($code)) {
            Notification::make()->title(__('couriers.sync.not_enabled'))->warning()->send();

            return;
        }

        // Atomic: only the first of two clicks inside five minutes gets through.
        if (! Cache::add('couriers:sync-now:'.$code, 1, 300)) {
            Notification::make()->title(__('couriers.sync.throttled'))->warning()->send();

            return;
        }

        SyncCourierPointsJob::dispatch($code, 'manual');

        Notification::make()
            ->title(__('couriers.sync.queued', ['courier' => app(CarrierRegistry::class)->find($code)?->name ?? $code]))
            ->success()
            ->send();
    }

    /** none | saved | unreadable; the secret itself never reaches this class. */
    private function secretState(string $code, CourierField $field, CourierEnvironment $environment): string
    {
        return $this->secretStates["{$code}.{$environment->value}.{$field->name}"] ??= app(CourierSettings::class)->secretState($code, $field, $environment);
    }

    private function connectionHtml(string $code): HtmlString
    {
        $result = $this->connection[$code] ?? null;

        if ($result === null) {
            return new HtmlString('');
        }

        if ($result['ok']) {
            return new HtmlString('<strong>✓ </strong>'.e(__('couriers.test.ok')));
        }

        $closed = (string) $result['code'];

        return new HtmlString('<strong>✗ </strong>'.e(__('couriers.test.failed', ['message' => $this->errorText($closed, $code), 'code' => $closed])));
    }

    /** The text of a CLOSED code; anything else is «неизвестна грешка», so nothing a courier said can reach the page. */
    private function errorText(string $code, ?string $courier = null): string
    {
        // `courier_error:7`: the closed code plus the INTEGER a courier sent (never its text): «Грешка от Спиди (код 7)».
        if (preg_match('/^courier_error:(\d{1,6})$/D', $code, $m) === 1) {
            return __('couriers.errors.courier_error_code', ['courier' => CourierDirectory::label($courier ?? ''), 'code' => $m[1]]);
        }

        $key = 'couriers.errors.'.$code;
        $text = __($key);

        return $text === $key ? __('couriers.errors.unknown_error') : $text;
    }

    /** The short settlement report: counts and a handful of names, derived on every render. */
    private function reportHtml(string $code): HtmlString
    {
        $report = app(SettlementReport::class)->for($code);
        $built = app(SiteSettingsRepository::class)->get(app(CourierSettings::class)->key($code, SettlementSyncer::SYNCED_AT));
        $lines = ['<strong>'.e(__('couriers.report.heading')).'</strong>'];

        if (! isset(SettlementBuilder::COLUMNS[$code])) {
            $lines[] = e(__('couriers.report.no_settlements'));
        }

        $lines[] = e(__('couriers.report.line', ['mapped' => $report['mapped'], 'unmatched' => $report['unmatched'], 'splits' => $report['splits'], 'total' => $report['points_total'], 'unmapped' => $report['points_unmapped']]));

        if ($report['unmatched_names'] !== []) {
            $lines[] = e(__('couriers.report.unmatched_names', ['names' => implode(', ', $report['unmatched_names'])]));
        }

        if ($report['split_names'] !== []) {
            $lines[] = e(__('couriers.report.split_names', ['names' => implode(', ', $report['split_names'])]));
        }

        $lines[] = e($built === null
            ? __('couriers.report.never_built')
            : __('couriers.report.built_at', ['when' => (new DateTimeImmutable($built, new \DateTimeZone('UTC')))->setTimezone(app(StoreTimezone::class)->zone())->format('Y-m-d H:i')]));

        return new HtmlString(implode('<br>', $lines));
    }

    private function lastRunHtml(string $code, string $name): HtmlString
    {
        $run = CourierSyncRun::query()->where('carrier_code', $code)->orderByDesc('started_at')->orderByDesc('id')->first();
        $heading = '<strong>'.e(__('couriers.sync.heading')).'</strong><br>';

        if ($run === null) {
            return new HtmlString($heading.e(__('couriers.sync.never')));
        }

        $when = $run->started_at->setTimezone(app(StoreTimezone::class)->zone())->format('Y-m-d H:i');
        $text = __('couriers.sync.last', [
            'when' => $when,
            'outcome' => __('couriers.sync.outcome.'.$run->outcome),
            'seen' => $run->seen,
            'new' => $run->new_count,
            'changed' => $run->changed,
            'deactivated' => $run->deactivated,
            'skipped' => $run->skipped,
        ]);

        $html = $heading.e($text);

        if ($run->error_code !== null) {
            $html .= '<br>'.e(__('couriers.sync.last_error', ['message' => $this->errorText((string) $run->error_code, $code), 'code' => (string) $run->error_code]));
        }

        return new HtmlString($html);
    }
}
