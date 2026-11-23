<?php

namespace App\Filament\Pages;

use App\Customers\CustomerIdentity;
use App\Customers\CustomerPageReader;
use App\Enums\OrderBlockType;
use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\Support\HelpLink;
use App\Filament\Support\OrderBlockActions;
use App\Models\OrderBlockModel;
use App\Privacy\Persistence\CustomerNoteModel;
use App\Privacy\Persistence\PrivacyRequestModel;
use App\Privacy\PrivacyConfirmationRequester;
use App\Privacy\PrivacyEraser;
use App\Privacy\PrivacyExportRequester;
use App\Privacy\PrivacyHash;
use App\Privacy\PrivacyMailNotQueued;
use App\Privacy\PrivacyNotConfigured;
use App\Privacy\PrivacyRequestAlreadyOpen;
use App\Privacy\PrivacySource;
use App\Privacy\StaffActor;
use App\Filament\Design\InfoHint;
use Filament\Forms\Components\Textarea;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Validation\ValidationException;
use App\Services\OrderBlockService;
use App\Services\PanelStaffActor;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Attributes\Locked;

/**
 * ACC-4e-1 (acc-4-gdpr-design.md D5, §7): the admin customer page, `/admin/customers/{key}` with the key `o-{orderId}` (any order of the person) or `a-{accountId}` (an account with no order):
 * the URL NEVER carries the email, and an unknown, malformed or anonymised key is a 404 (CustomerIdentity). Read sections come from CustomerPageReader (a constant number of queries); the
 * actions are the staff note (CUSTOMER_VIEW), «Изтегли данните», «Поискай потвърждение» and «Изпрати отново» (PRIVACY_MANAGE) and the COD blocks (ORDER_MANAGE, the order view's own permission).
 *
 * EVERY permission is checked ON THE SERVER, on every Livewire request and again inside each action: a hidden button is a convenience, never the protection. A Livewire argument the client can edit
 * (a phone, a request id) is NEVER trusted: it must be one of THIS person's own phones / requests, re-read on the server. The COD actions call the order view's own service
 * (OrderBlockService) and its own duration form (OrderBlockActions::durationFields): no second implementation.
 *
 * NO «Забрави», no forget log, no tile and no privacy settings here: they are ACC-4e-2.
 */
class CustomerPage extends Page
{
    use AuthorizesViaStaffPermission;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'customer';

    protected string $view = 'filament.pages.customer';

    #[Locked]
    public string $key = '';

    public string $noteBody = '';

    /** @var array<string, mixed>|null the page's data, read once per request */
    private ?array $data = null;

    private ?CustomerIdentity $identity = null;

    public static function getRoutePath(Panel $panel): string
    {
        return '/customers/{key}';
    }

    protected static function accessPermission(): ?Permission
    {
        return Permission::CUSTOMER_VIEW;
    }

    public static function canAccess(): bool
    {
        return static::staffCanForAction(static::accessPermission());
    }

    protected static function mayPrivacy(): bool
    {
        return static::staffCanForAction(Permission::PRIVACY_MANAGE);
    }

    /** Runs on EVERY request of this component. */
    public function boot(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function mount(string $key): void
    {
        abort_unless(static::canAccess(), 403);

        $this->key = $key;
        $this->identity() ?? abort(404);
        $this->noteBody = (string) $this->data()['note'];
    }

    public function getTitle(): string
    {
        return (string) ($this->data()['person']['name'] ?? $this->data()['person']['email'] ?? __('customers.page.unknown_name'));
    }

    public function getSubheading(): ?string
    {
        $person = $this->data()['person'];

        return trim($person['email'].($person['phone'] ? ' · '.$person['phone'] : ''));
    }

    public function getBreadcrumbs(): array
    {
        return [Customers::getUrl() => __('customers.navigation_label'), '#' => $this->getTitle()];
    }

    /** @return array<string, mixed> */
    protected function data(): array
    {
        return $this->data ??= app(CustomerPageReader::class)->read($this->identity() ?? abort(404));
    }

    protected function identity(): ?CustomerIdentity
    {
        return $this->identity ??= CustomerIdentity::resolve($this->key);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['d' => $this->data(), 'mayPrivacy' => static::mayPrivacy(), 'mayBlock' => OrderBlockActions::mayManage()];
    }

    // ---- the staff note ---------------------------------------------------------------------------------------------------------

    public function saveNote(): void
    {
        abort_unless(static::canAccess(), 403);

        $body = trim($this->noteBody);

        // plain text, at most 2000 characters; a note is several lines, so a newline and a tab are allowed (the other control characters and the bidi overrides are not)
        if (mb_strlen($body) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $body) === 1) {
            Notification::make()->title(__('customers.note.invalid'))->danger()->send();

            return;
        }

        $emailKey = $this->identity()?->emailKey ?? abort(404);

        if ($body === '') {
            CustomerNoteModel::query()->where('email_key', $emailKey)->delete();
        } else {
            CustomerNoteModel::query()->updateOrCreate(['email_key' => $emailKey], ['body' => $body, 'staff_id' => $this->staff()?->id]);
        }

        $this->data = null;
        Notification::make()->title(__('customers.note.saved'))->success()->send();
    }

    // ---- the privacy actions (PRIVACY_MANAGE) -----------------------------------------------------------------------------------

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [$this->exportAction(), $this->confirmationAction(), $this->forgetAction()];
    }

    public function exportAction(): Action
    {
        return Action::make('export')
            ->label(__('customers.actions.export'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('primary')
            ->visible(fn (): bool => static::mayPrivacy() && $this->data()['privacy_ready'])
            ->action(function (): void {
                abort_unless(static::mayPrivacy(), 403);

                try {
                    // a CONFIRMED export request of this email is built on, not duplicated (PrivacyExportRequester)
                    app(PrivacyExportRequester::class)->request($this->identity()?->emailKey ?? abort(404), $this->staff());
                } catch (PrivacyNotConfigured) {
                    Notification::make()->title(__('customers.actions.export_failed'))->danger()->send();

                    return;
                }

                $this->data = null;
                Notification::make()->title(__('customers.actions.export_preparing'))->success()->send();
            });
    }

    public function confirmationAction(): Action
    {
        return Action::make('confirmation')
            ->label(__('customers.actions.request_confirmation'))
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->visible(fn (): bool => static::mayPrivacy() && $this->data()['privacy_ready'])
            ->modalHeading(__('customers.confirm.heading'))
            ->modalSubmitActionLabel(__('customers.confirm.send'))
            ->modalWidth('md')
            ->schema([
                Radio::make('kind')
                    ->label(__('customers.confirm.kind'))
                    ->options([
                        PrivacyRequestModel::KIND_EXPORT => __('customers.confirm.kind_export'),
                        PrivacyRequestModel::KIND_ERASE => __('customers.confirm.kind_erase'),
                    ])
                    ->default(PrivacyRequestModel::KIND_EXPORT)
                    ->required()
                    ->live(),
                Text::make(fn (): string => (string) __('customers.confirm.goes_to', ['email' => $this->identity()?->emailKey])),
                Text::make(function (Get $get): string {
                    $open = $this->openRow((string) $get('kind'));

                    return $open === null ? '' : (string) __('customers.confirm.already', ['email' => $this->identity()?->emailKey, 'date' => $this->endDate($open)]);
                })->color('warning'),
            ])
            ->action(function (array $data): void {
                abort_unless(static::mayPrivacy(), 403);

                $emailKey = $this->identity()?->emailKey ?? abort(404);

                try {
                    app(PrivacyConfirmationRequester::class)->request($emailKey, (string) ($data['kind'] ?? ''), $this->staff());
                } catch (PrivacyRequestAlreadyOpen $e) {
                    // the dialog turns into «Вече е пратено на …» with the button «Изпрати отново»; no second row was made
                    $this->replaceMountedAction('resend', ['id' => $e->request->getKey()]);

                    return;
                } catch (PrivacyMailNotQueued) {
                    $this->data = null;
                    Notification::make()->title(__('customers.confirm.mail_failed'))->danger()->send();

                    return;
                } catch (InvalidArgumentException|PrivacyNotConfigured) {
                    Notification::make()->title(__('customers.confirm.invalid'))->danger()->send();

                    return;
                }

                $this->data = null;
                Notification::make()->title(__('customers.confirm.sent'))->success()->send();
            });
    }

    /**
     * «Забрави» (ACC-4e-2, acc-4-gdpr-design.md section 4 and 10): PRIVACY_MANAGE (Administrator only by default), checked again on the server. The dialog says what goes and what stays and asks a REQUIRED reason
     * (3 to 500 characters of plain text); the erase itself is PrivacyEraser::erase(), which takes the confirmed erase request of this email as its log row when there is one. This action repeats no erase logic.
     */
    public function forgetAction(): Action
    {
        return Action::make('forget')
            ->label(__('privacy_admin.forget.action'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (): bool => static::mayPrivacy() && $this->data()['privacy_ready'])
            ->modalHeading(fn (): string => (string) __('privacy_admin.forget.heading', ['name' => $this->getTitle()]))
            ->modalWidth('lg')
            ->modalContent(fn (): \Illuminate\Contracts\View\View => view('filament.customers.forget-summary', ['s' => app(CustomerPageReader::class)->forgetSummary($this->identity() ?? abort(404), $this->data())]))
            ->schema([
                Textarea::make('reason')
                    ->label(__('privacy_admin.forget.reason'))
                    ->afterLabel(InfoHint::make(__('privacy_admin.forget.reason_hint'), 'forget-reason'))
                    ->rows(3)
                    ->required()
                    ->rules([static fn (): \Closure => static function (string $attribute, mixed $value, \Closure $fail): void {
                        self::cleanReason((string) $value) === null && $fail(__('privacy_admin.forget.reason_invalid'));
                    }]),
            ])
            ->modalSubmitActionLabel(__('privacy_admin.forget.submit'))
            ->modalCancelActionLabel(__('privacy_admin.forget.cancel'))
            ->action(function (array $data): void {
                abort_unless(static::mayPrivacy(), 403);

                $reason = self::cleanReason((string) ($data['reason'] ?? ''));
                $emailKey = $this->identity()?->emailKey ?? abort(404);

                if ($reason === null) {
                    Notification::make()->title(__('privacy_admin.forget.reason_invalid'))->danger()->send();

                    return;
                }

                try {
                    app(PrivacyEraser::class)->erase($emailKey, PrivacySource::ADMIN, $this->staff(), $reason);
                } catch (ValidationException|InvalidArgumentException|PrivacyNotConfigured|LockTimeoutException) {
                    Notification::make()->title(__('privacy_admin.forget.failed'))->danger()->send();

                    return;
                }

                Notification::make()->title(__('privacy_admin.forget.done'))->success()->send();

                // an account-only person has nothing left to show once the account is gone: back to the list; anyone with an order keeps their page (now «Забравен», no account, no note)
                if (str_starts_with($this->key, 'a-')) {
                    $this->redirect(Customers::getUrl());

                    return;
                }

                $this->identity = null;
                $this->data = null;
                $this->noteBody = '';
            });
    }

    /** The reason as it is kept: whitespace (newlines too) collapsed to single spaces, 3 to 500 characters, no control or bidi characters; null when it is none of that. */
    public static function cleanReason(string $reason): ?string
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500 || preg_match('/[\p{Cc}\p{Cf}]/u', $reason) === 1) {
            return null;
        }

        return $reason;
    }

    /** «Изпрати отново»: a NEW signed link for the SAME row. The row must be one of THIS person's (re-read here, the id comes from the client). */
    public function resendAction(): Action
    {
        return Action::make('resend')
            ->label(__('customers.requests.resend'))
            ->size('xs')
            ->color('gray')
            ->visible(fn (): bool => static::mayPrivacy() && $this->data()['privacy_ready'])
            ->modalHeading(__('customers.confirm.already_heading'))
            ->modalDescription(function (array $arguments): string {
                $row = $this->ownRow((int) ($arguments['id'] ?? 0));

                return $row === null ? '' : (string) __('customers.confirm.already', ['email' => $this->identity()?->emailKey, 'date' => $this->endDate($row)]);
            })
            ->modalSubmitActionLabel(__('customers.confirm.resend'))
            ->action(function (array $arguments): void {
                abort_unless(static::mayPrivacy(), 403);

                $row = $this->ownRow((int) ($arguments['id'] ?? 0));

                try {
                    $row === null && throw new InvalidArgumentException('not this customer\'s request');
                    app(PrivacyConfirmationRequester::class)->resend($row, $this->staff());
                } catch (PrivacyMailNotQueued) {
                    Notification::make()->title(__('customers.confirm.mail_failed'))->danger()->send();

                    return;
                } catch (InvalidArgumentException) {
                    Notification::make()->title(__('customers.confirm.not_resendable'))->danger()->send();

                    return;
                }

                $this->data = null;
                Notification::make()->title(__('customers.confirm.resent'))->success()->send();
            });
    }

    // ---- the cash-on-delivery card (ORDER_MANAGE: the order view's own permission) ----------------------------------------------

    /** «Блокирай»: the order view's own dialog (duration + reason) and its own service. */
    public function blockAction(): Action
    {
        return Action::make('block')
            ->label(__('blocks.actions.block'))
            ->icon('heroicon-m-no-symbol')
            ->color('danger')
            ->size('xs')
            ->modalHeading(fn (array $arguments): string => (string) __('blocks.actions.block_heading', ['type' => mb_strtolower((string) __('blocks.type_options.'.($arguments['type'] ?? 'phone')))]))
            ->modalDescription(__('blocks.form.note'))
            ->modalSubmitActionLabel(__('blocks.actions.block'))
            ->schema([...OrderBlockActions::durationFields(), HelpLink::component('block_identifier')])
            ->visible(fn (): bool => OrderBlockActions::mayManage())
            ->action(function (array $data, array $arguments): void {
                abort_unless(OrderBlockActions::mayManage(), 403);

                $target = $this->blockTarget($arguments);

                try {
                    $target === null && throw new InvalidArgumentException('not this customer\'s value');
                    app(OrderBlockService::class)->block($target['type'], $target['value'], OrderBlockActions::daysFrom($data), $data['reason'] ?? null, $target['order']);
                } catch (InvalidArgumentException) {
                    Notification::make()->title(__('blocks.notice.cannot_block'))->danger()->send();

                    return;
                }

                $this->data = null;
                Notification::make()->title(__('blocks.notice.blocked'))->success()->send();
            });
    }

    /** «Махни»: the order view's own confirmation and its own `revoke`. */
    public function unblockAction(): Action
    {
        return Action::make('unblock')
            ->label(__('blocks.actions.unblock'))
            ->icon('heroicon-m-lock-open')
            ->color('gray')
            ->size('xs')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => (string) __('blocks.actions.unblock_heading', ['type' => mb_strtolower((string) __('blocks.type_options.'.($arguments['type'] ?? 'phone')))]))
            ->modalDescription(fn (array $arguments): string => (string) __('blocks.actions.unblock_description', ['type' => mb_strtolower((string) __('blocks.type_options.'.($arguments['type'] ?? 'phone')))]))
            ->visible(fn (): bool => OrderBlockActions::mayManage())
            ->action(function (array $arguments): void {
                abort_unless(OrderBlockActions::mayManage(), 403);

                $target = $this->blockTarget($arguments);
                $block = $target === null ? null : $this->activeBlock($target['type'], $target['key']);

                if ($block === null) {
                    Notification::make()->title(__('blocks.notice.cannot_block'))->danger()->send();

                    return;
                }

                app(OrderBlockService::class)->revoke($block);
                $this->data = null;
                Notification::make()->title(__('blocks.notice.unblocked'))->success()->send();
            });
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------------------

    /**
     * What the client asked to block, checked against THIS customer: the email (the person's own) or a phone whose key is one of the person's phones. Anything else is null.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{type: OrderBlockType, key: string, value: string, order: ?string}|null
     */
    private function blockTarget(array $arguments): ?array
    {
        $emailKey = $this->identity()?->emailKey ?? abort(404);
        $latest = $this->data()['orders'][0]['id'] ?? null;

        if (($arguments['type'] ?? null) === 'email') {
            $value = (string) ($this->data()['person']['email'] ?? $emailKey);

            return ['type' => OrderBlockType::EMAIL, 'key' => $emailKey, 'value' => $value, 'order' => $latest === null ? null : (string) $latest];
        }

        if (($arguments['type'] ?? null) === 'phone' && is_string($arguments['key'] ?? null)) {
            foreach ($this->data()['phones'] as $phone) {
                if ($phone['key'] !== null && $phone['key'] === $arguments['key']) {
                    $order = DB::table('orders')->where('email_key', $emailKey)->where('phone_key', $phone['key'])->orderByDesc('id')->value('id');

                    return ['type' => OrderBlockType::PHONE, 'key' => $phone['key'], 'value' => $phone['display'], 'order' => $order === null ? null : (string) $order];
                }
            }
        }

        return null;
    }

    private function activeBlock(OrderBlockType $type, string $key): ?OrderBlockModel
    {
        return app(OrderBlockService::class)->activeForPerson(
            $type === OrderBlockType::EMAIL ? $key : null,
            $type === OrderBlockType::PHONE ? [$key] : [],
        )->first(static fn (OrderBlockModel $block): bool => $block->type === $type->value && $block->value_key === $key);
    }

    /** A privacy request that belongs to THIS person (by keyed hash), or null. */
    private function ownRow(int $id): ?PrivacyRequestModel
    {
        if ($id < 1) {
            return null;
        }

        try {
            $hash = (string) app(PrivacyHash::class)->email($this->identity()?->emailKey);
        } catch (PrivacyNotConfigured) {
            return null;
        }

        return PrivacyRequestModel::query()->whereKey($id)->where('email_hash', $hash)->first();
    }

    private function openRow(string $kind): ?PrivacyRequestModel
    {
        try {
            $hash = (string) app(PrivacyHash::class)->email($this->identity()?->emailKey);
        } catch (PrivacyNotConfigured) {
            return null;
        }

        return in_array($kind, PrivacyConfirmationRequester::KINDS, true) ? app(PrivacyConfirmationRequester::class)->openRow($hash, $kind) : null;
    }

    private function endDate(PrivacyRequestModel $row): string
    {
        return Carbon::createFromTimestamp(app(PrivacyConfirmationRequester::class)->endsAt($row))->timezone(FilamentTimezone::get())->format('d.m.Y H:i');
    }

    private function staff(): ?StaffActor
    {
        $staff = app(PanelStaffActor::class)->current();

        return $staff === null ? null : new StaffActor((int) $staff->getKey(), (string) $staff->name);
    }
}
