<?php

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Support\HelpLink;
use App\Mail\Dns\DnsChecker;
use App\Mail\MailConfigurator;
use App\Mail\MailHeader;
use App\Mail\QueueHealth;
use App\Mail\SenderIdentity;
use App\Mail\TestMailSender;
use App\Mail\Transports\MailTransport;
use App\Mail\Transports\MailTransports;
use App\Mail\Transports\SmtpTransport;
use App\Mail\Transports\TransportField;
use App\Rules\PlainText;
use App\Services\ActivityLogger;
use App\Settings\Contracts\SiteSettingsRepository;
use BackedEnum;
use Carbon\CarbonInterval;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * "Mail" (mail-design.md §2, §3, §4, stage M2): how the shop sends email. Permission settings_manage, same shape as
 * BankTransferSettings (SiteSettingsRepository, one Save).
 *
 * NOTHING HERE IS ON A CUSTOMER PATH: the page only writes `mail.*` settings; the checkout never reads them (the
 * queued job does, through MailConfigurator, inside the worker).
 *
 * SECRETS: the SMTP password is WRITE-ONLY. It is stored with Crypt::encryptString under mail.smtp.password, never
 * filled into the form, never rendered, and removed from the Livewire state in dehydrate() so it is not carried in
 * the page snapshot (the typed value reaches the server only inside the request that saves it). An empty submit
 * keeps the stored value; "Remove saved password" deletes it. An unreadable stored value (APP_KEY changed) is shown as
 * "re-enter the password", never thrown. The activity journal records that a field "changed", never a value.
 *
 * EVERY public method re-checks settings_manage itself: Livewire lets a browser call any public method, so hiding a
 * button or the navigation entry is not authorisation.
 *
 * The transport fields come from the MailTransports registry (App\Mail\Transports): an API provider is one more entry
 * there, this page renders whatever fields it declares.
 */
class MailSettings extends Page
{
    use AuthorizesViaStaffPermission;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** The result of the last "Send test email": text is already sanitised. */
    public ?string $testResult = null;

    public bool $testOk = false;

    /** @var list<array{domain: string, check: string, status: string}> */
    public array $dnsResults = [];

    public ?string $dnsMessage = null;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    private const TRANSPORT_KEY = 'mail.transport';

    /** Plain form field => the site_settings key it is stored under. The transport fields come from the registry. */
    private const KEYS = [
        'from_transactional_address' => 'mail.from.transactional.address',
        'from_transactional_name' => 'mail.from.transactional.name',
        'from_marketing_address' => 'mail.from.marketing.address',
        'from_marketing_name' => 'mail.from.marketing.name',
        'reply_to' => 'mail.reply_to',
        'dns_include' => 'mail.dns.include',
        'dns_selector' => 'mail.dns.selector',
    ];

    private const DOMAIN_CONFIRMED = 'mail.sender.domain_confirmed';

    /** @var array<string, string> request-lifetime memo of secretState() */
    private array $secretStates = [];

    /** @var array<string, array{pending: int, oldest_age_seconds: ?int}>|null */
    private ?array $queueSnapshot = null;

    private bool $queueLoaded = false;

    public function getTitle(): string
    {
        return __('mail.page.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('mail.page.navigation_label');
    }

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::ADMIN;
    }

    public static function getNavigationSort(): ?int
    {
        return 32;
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
        $transports = app(MailTransports::class);
        $stored = trim((string) $settings->get(self::TRANSPORT_KEY));

        $data = ['transport' => $transports->find($stored) !== null ? $stored : MailTransports::ENV];

        foreach ($transports->all() as $transport) {
            foreach ($transport->fields() as $field) {
                // A secret is NEVER filled into the form.
                $data[$field->name] = $field->isSecret() ? null : ($settings->get($field->settingKey) ?? $field->default);
            }
        }

        foreach (self::KEYS as $name => $key) {
            $data[$name] = $settings->get($key);
        }

        $data['domain_confirmed'] = $settings->get(self::DOMAIN_CONFIRMED) === '1';
        $data['test_recipient'] = $this->staffEmail();

        $this->form->fill($data);
    }

    /** Livewire lifecycle hook: runs before the snapshot is built, so a typed secret never rides in it. */
    public function dehydrate(): void
    {
        foreach ($this->secretFieldNames() as $name) {
            if (is_array($this->data) && array_key_exists($name, $this->data)) {
                $this->data[$name] = null;
            }
        }
    }

    public function form(Schema $schema): Schema
    {
        $transports = app(MailTransports::class);

        $options = [MailTransports::ENV => __('mail.transport.env')];

        foreach ($transports->all() as $transport) {
            $options[$transport->key()] = __('mail.'.$transport->label());
        }

        $transportComponents = [
            Select::make('transport')
                ->label(__('mail.transport.label'))
                ->helperText(__('mail.transport.help'))
                ->options($options)
                ->native(false)
                ->selectablePlaceholder(false)
                ->live()
                ->required(),
        ];

        foreach ($transports->all() as $transport) {
            foreach ($transport->fields() as $field) {
                $transportComponents[] = $this->fieldComponent($transport, $field);
            }
        }

        $transportComponents[] = Actions::make([
            Action::make('removePassword')
                ->label(__('mail.smtp.remove_password'))
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(__('mail.smtp.remove_password_confirm'))
                ->visible(fn (): bool => $this->secretState(SmtpTransport::PASSWORD) !== 'none')
                ->action(fn () => $this->removePassword()),
        ])->key('password-actions');

        return $schema
            ->components([
                Text::make(__('mail.page.intro')),
                HelpLink::component('mail_settings', 'mail'),

                Section::make(__('mail.page.section_status'))
                    ->schema([
                        Text::make(fn (): HtmlString => $this->statusHtml()),
                    ]),

                Section::make(__('mail.page.section_transport'))->schema($transportComponents),

                Section::make(__('mail.page.section_sender'))
                    ->schema([
                        Text::make(__('mail.sender.help')),
                        TextInput::make('from_transactional_address')
                            ->label(__('mail.sender.transactional_address'))
                            ->maxLength(254)
                            ->rules([SenderIdentity::addressRule()]),
                        TextInput::make('from_transactional_name')
                            ->label(__('mail.sender.transactional_name'))
                            ->maxLength(SenderIdentity::MAX_NAME * 4)
                            ->rules([SenderIdentity::nameRule()]),
                        TextInput::make('from_marketing_address')
                            ->label(__('mail.sender.marketing_address'))
                            ->maxLength(254)
                            ->rules([SenderIdentity::addressRule()]),
                        TextInput::make('from_marketing_name')
                            ->label(__('mail.sender.marketing_name'))
                            ->maxLength(SenderIdentity::MAX_NAME * 4)
                            ->rules([SenderIdentity::nameRule()]),
                        TextInput::make('reply_to')
                            ->label(__('mail.sender.reply_to'))
                            ->helperText(__('mail.sender.reply_to_help'))
                            ->maxLength(254)
                            ->rules([SenderIdentity::addressRule()]),
                        Toggle::make('domain_confirmed')
                            ->label(__('mail.sender.domain_confirmed'))
                            ->helperText(__('mail.sender.domain_confirmed_help')),
                    ]),

                Section::make(__('mail.page.section_test'))
                    ->schema([
                        TextInput::make('test_recipient')
                            ->label(__('mail.test.recipient'))
                            ->helperText(__('mail.test.recipient_help'))
                            ->maxLength(254),
                        Actions::make([
                            Action::make('sendTest')
                                ->label(__('mail.test.button'))
                                ->action(fn () => $this->sendTestEmail()),
                        ])->key('test-actions'),
                        Text::make(fn (): HtmlString => $this->testResultHtml())
                            ->visible(fn (): bool => $this->testResult !== null),
                    ]),

                Section::make(__('mail.page.section_dns'))
                    ->schema([
                        Text::make(__('mail.dns.help')),
                        TextInput::make('dns_include')
                            ->label(__('mail.dns.include'))
                            ->helperText(__('mail.dns.include_help'))
                            ->maxLength(253),
                        TextInput::make('dns_selector')
                            ->label(__('mail.dns.selector'))
                            ->helperText(__('mail.dns.selector_help'))
                            ->maxLength(100),
                        Actions::make([
                            Action::make('checkDns')
                                ->label(__('mail.dns.button'))
                                ->action(fn () => $this->checkDns()),
                        ])->key('dns-actions'),
                        Text::make(fn (): HtmlString => $this->dnsHtml())
                            ->visible(fn (): bool => $this->dnsResults !== [] || $this->dnsMessage !== null),
                    ]),

                Section::make(__('mail.page.section_queue'))
                    ->schema([
                        Text::make(fn (): HtmlString => $this->queueHtml()),
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
                        ->label(__('mail.page.save_label'))
                        ->submit('save'),
                ])->key('form-actions'),
            ]);
    }

    private function fieldComponent(MailTransport $transport, TransportField $field): Component
    {
        $visible = fn (Get $get): bool => $get('transport') === $transport->key();
        $label = __('mail.'.$field->label);

        if ($field->kind === TransportField::SELECT) {
            return Select::make($field->name)
                ->label($label)
                ->options(array_map(fn (string $key): string => __('mail.'.$key), $field->options))
                ->native(false)
                ->selectablePlaceholder(false)
                ->required($field->required)
                ->visible($visible);
        }

        if ($field->kind === TransportField::SECRET) {
            return TextInput::make($field->name)
                ->label($label)
                ->password()
                ->autocomplete('new-password')
                ->placeholder(fn (): ?string => $this->secretState($field->settingKey) === 'saved' ? __('mail.smtp.password_saved') : null)
                ->helperText(fn (): string => __('mail.smtp.password_help_'.$this->secretState($field->settingKey)))
                ->maxLength($field->max)
                ->rules(['nullable', 'not_regex:/[\x00-\x1F\x7F]/'])
                ->validationMessages(['not_regex' => __('mail.validation.password_plain')])
                ->visible($visible);
        }

        $input = TextInput::make($field->name)
            ->label($label)
            ->required($field->required)
            ->visible($visible);

        if ($field->kind === TransportField::NUMBER) {
            $input->numeric()->integer()->minValue($field->min)->maxValue($field->max);
        } else {
            $input->maxLength($field->max)->rules([new PlainText()]);
        }

        if ($field->rules !== []) {
            $input->rules($field->rules);
        }

        if ($field->name === 'smtp_host') {
            $input->helperText(__('mail.smtp.host_help'));
        }

        return $input;
    }

    public function save(): void
    {
        $this->guard();

        $data = $this->form->getState();
        $settings = app(SiteSettingsRepository::class);
        $transports = app(MailTransports::class);
        $journal = app(ActivityLogger::class);
        $changed = [];

        $write = function (string $key, ?string $value) use ($settings, &$changed): void {
            $old = $settings->get($key);
            $value = $value === null || $value === '' ? null : $value;

            if ($value === null) {
                $settings->forget($key);
            } else {
                $settings->set($key, $value);
            }

            if ($old !== $value) {
                $changed[] = $key;
            }
        };

        $chosen = (string) ($data['transport'] ?? MailTransports::ENV);
        $selected = $transports->find($chosen);

        $write(self::TRANSPORT_KEY, $selected === null ? null : $selected->key());

        // Only the selected transport's fields are written: the others were hidden, so absent from the state.
        foreach ($selected?->fields() ?? [] as $field) {
            $value = $data[$field->name] ?? null;
            $value = is_scalar($value) ? trim((string) $value) : '';

            if ($field->isSecret()) {
                // Write-only: empty keeps the stored value; a typed one replaces it, encrypted. The raw value goes
                // nowhere else (not into $changed, not into the journal).
                if ($value !== '') {
                    $settings->set($field->settingKey, Crypt::encryptString($value));
                    $changed[] = $field->settingKey;
                }

                continue;
            }

            if ($field->kind === TransportField::SELECT && ! array_key_exists($value, $field->options)) {
                $value = (string) $field->default;
            }

            $write($field->settingKey, $value);
        }

        foreach (self::KEYS as $name => $key) {
            $value = $data[$name] ?? null;
            $write($key, is_scalar($value) ? trim((string) $value) : null);
        }

        $write(self::DOMAIN_CONFIRMED, ! empty($data['domain_confirmed']) ? '1' : null);

        foreach ($changed as $key) {
            // The fact that it changed, never the value (the password is among these keys).
            $journal->logFieldChanged('mail_settings', 'mail', $key, null, 'changed');
        }

        $this->data['smtp_password'] = null;
        $this->secretStates = [];

        Notification::make()
            ->title(__('mail.page.saved_notification'))
            ->success()
            ->send();
    }

    public function removePassword(): void
    {
        $this->guard();

        $settings = app(SiteSettingsRepository::class);

        if ($settings->get(SmtpTransport::PASSWORD) !== null) {
            $settings->forget(SmtpTransport::PASSWORD);
            app(ActivityLogger::class)->logFieldChanged('mail_settings', 'mail', SmtpTransport::PASSWORD, null, 'removed');
        }

        $this->secretStates = [];

        Notification::make()
            ->title(__('mail.smtp.password_removed'))
            ->success()
            ->send();
    }

    public function sendTestEmail(): void
    {
        $this->guard();

        $recipient = MailHeader::address((string) ($this->data['test_recipient'] ?? ''));

        if ($recipient === null) {
            $this->setTestResult(false, __('mail.test.invalid_address'));

            return;
        }

        // Another address than your own: at most one per minute per staff member.
        if (strcasecmp($recipient, $this->staffEmail()) !== 0) {
            $key = 'mail-test:'.(string) Filament::auth()->id();

            if (RateLimiter::tooManyAttempts($key, 1)) {
                $this->setTestResult(false, __('mail.test.rate_limited', ['seconds' => RateLimiter::availableIn($key)]));

                return;
            }

            RateLimiter::hit($key, 60);
        }

        $error = app(TestMailSender::class)->send($recipient);

        $error === null
            ? $this->setTestResult(true, __('mail.test.success', ['to' => $recipient]))
            : $this->setTestResult(false, __('mail.test.failed', ['error' => $error]));
    }

    public function checkDns(): void
    {
        $this->guard();

        $this->dnsResults = [];
        $this->dnsMessage = null;

        try {
            $domains = [];

            foreach (['from_transactional_address', 'from_marketing_address'] as $field) {
                $domain = SenderIdentity::domainOf((string) ($this->data[$field] ?? ''));

                if ($domain !== null) {
                    $domains[$domain] = true;
                }
            }

            if ($domains === []) {
                $this->dnsMessage = __('mail.dns.no_domain');

                return;
            }

            $checker = app(DnsChecker::class);
            $include = (string) ($this->data['dns_include'] ?? '');
            $selector = (string) ($this->data['dns_selector'] ?? '');

            foreach (array_keys($domains) as $domain) {
                foreach ($checker->check($domain, $include, $selector) as $result) {
                    $this->dnsResults[] = ['domain' => $domain] + $result;
                }
            }
        } catch (Throwable) {
            $this->dnsResults = [];
            $this->dnsMessage = __('mail.dns.error');
        }
    }

    private function setTestResult(bool $ok, string $text): void
    {
        $this->testOk = $ok;
        $this->testResult = $text;
    }

    private function staffEmail(): string
    {
        return (string) (Filament::auth()->user()?->email ?? '');
    }

    /** @return list<string> */
    private function secretFieldNames(): array
    {
        $names = [];

        foreach (app(MailTransports::class)->all() as $transport) {
            foreach ($transport->fields() as $field) {
                if ($field->isSecret()) {
                    $names[] = $field->name;
                }
            }
        }

        return $names;
    }

    /** none | saved | unreadable — decided inside MailConfigurator; the secret itself never reaches this class. */
    private function secretState(string $settingKey): string
    {
        return $this->secretStates[$settingKey] ??= app(MailConfigurator::class)->secretState($settingKey);
    }

    private function statusHtml(): HtmlString
    {
        $settings = app(SiteSettingsRepository::class);
        $lines = [];
        $transport = app(MailTransports::class)->find(trim((string) $settings->get(self::TRANSPORT_KEY)));

        if ($transport !== null) {
            $lines[] = __('mail.status.'.$transport->key(), [
                'host' => MailHeader::clean((string) $settings->get(SmtpTransport::HOST), 120),
                'port' => MailHeader::clean((string) ($settings->get(SmtpTransport::PORT) ?: '587'), 5),
            ]);

            if ($this->secretState(SmtpTransport::PASSWORD) === 'unreadable') {
                $lines[] = __('mail.status.password_unreadable');
            }
        } else {
            $mailer = (string) config('mail.default');
            $lines[] = match ($mailer) {
                'log' => __('mail.status.log'),
                'array' => __('mail.status.array'),
                default => __('mail.status.env_mailer', ['mailer' => $mailer]),
            };
        }

        $lines[] = $settings->get(self::DOMAIN_CONFIRMED) === '1' ? __('mail.status.sender_verified') : __('mail.status.sender_unverified');

        return new HtmlString(implode('<br>', array_map('e', $lines)));
    }

    private function testResultHtml(): HtmlString
    {
        return new HtmlString('<strong>'.($this->testOk ? '✓ ' : '✗ ').'</strong>'.e((string) $this->testResult));
    }

    private function dnsHtml(): HtmlString
    {
        if ($this->dnsMessage !== null) {
            return new HtmlString(e($this->dnsMessage));
        }

        $html = '';
        $current = null;

        foreach ($this->dnsResults as $row) {
            if ($row['domain'] !== $current) {
                $current = $row['domain'];
                $html .= '<strong>'.e(__('mail.dns.domain_heading', ['domain' => $current])).'</strong><br>';
            }

            $html .= e($this->dnsLine($row['check'], $row['status'])).'<br>';
        }

        return new HtmlString($html);
    }

    private function dnsLine(string $check, string $status): string
    {
        return match ($status) {
            'ok' => '✓ '.__('mail.dns.'.$check.'_ok'),
            'unseen' => '— '.__('mail.dns.'.$check.'_unseen'),
            'skipped' => '— '.__('mail.dns.skipped'),
            default => '— '.__('mail.dns.invalid'),
        };
    }

    private function queueHtml(): HtmlString
    {
        if (! $this->queueLoaded) {
            $this->queueSnapshot = app(QueueHealth::class)->snapshot();
            $this->queueLoaded = true;
        }

        if ($this->queueSnapshot === null) {
            return new HtmlString(e(__('mail.queue.not_available')));
        }

        $lines = [];

        foreach ($this->queueSnapshot as $queue => $row) {
            $name = __('mail.queue.name_'.($queue === 'mail-marketing' ? 'marketing' : 'transactional'));

            $lines[] = $row['pending'] === 0
                ? __('mail.queue.line_empty', ['queue' => $name])
                : __('mail.queue.line', [
                    'queue' => $name,
                    'count' => $row['pending'],
                    'age' => CarbonInterval::seconds((int) $row['oldest_age_seconds'])->cascade()->locale(app()->getLocale())->forHumans(['parts' => 2, 'short' => true]),
                ]);
        }

        $lines[] = __('mail.queue.worker_hint');

        return new HtmlString(implode('<br>', array_map('e', $lines)));
    }
}
