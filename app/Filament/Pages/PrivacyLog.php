<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Privacy\ForgetLogPresenter;
use App\Privacy\Persistence\PrivacyRequestModel;
use App\Privacy\PrivacyHash;
use App\Privacy\PrivacyNotConfigured;
use App\Privacy\PrivacySettings;
use App\Privacy\SuppressionList;
use App\Services\PanelStaffActor;
use BackedEnum;
use EasyCo\Order\IdentityKeys;
use EasyCo\Staff\Enums\Permission;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ACC-4e-2 (acc-4-gdpr-design.md D6, section 7): «Поверителност → Дневник». `/admin/privacy-log`, PRIVACY_MANAGE (Administrator only by default), checked at canAccess(), on EVERY Livewire request (`boot()`)
 * and again inside each action.
 *
 *  - LOOKUP: an email, normalised (IdentityKeys), hashed (PrivacyHash), looked up by `email_hash` over `kind = erase` AND `status = done`. The typed email is NEVER stored, logged or put in the URL: it
 *    lives only in the form's own field; the answer holds dates and states, never the email.
 *  - TABLE: the erase requests `done`, `processing` or `failed` (never the confirmation rows), newest first, 25 a page. Email and phone only while the clear view lasts (decided on READ, ForgetLogPresenter);
 *    no «Източник» column (owner 2026-11-23). A constant number of queries: the page, the count and the settings list of steps.
 *  - MANUAL STEPS: one checkbox per step of the CURRENT list in the settings (the list starts empty). A tick stores `{key, done_at, staff_id}` in the row's `manual_steps` under a row lock, so two staff
 *    members ticking different steps at once keep both; only keys of the current list are accepted; a step removed from the settings is no longer shown (its stored flag stays in the row).
 */
class PrivacyLog extends Page implements HasTable
{
    use AuthorizesViaStaffPermission;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $slug = 'privacy-log';

    /** The typed email: only the form's own field, never the URL (no #[Url]), never stored or logged. */
    public string $lookupEmail = '';

    /** @var array<string, mixed>|null the answer of the last lookup: states and dates, never the email */
    public ?array $lookupResult = null;

    /** @var list<array{key: string, label: string}>|null */
    private ?array $stepsCache = null;

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::PRIVACY;
    }

    public static function getNavigationSort(): ?int
    {
        return 10;
    }

    public static function getNavigationLabel(): string
    {
        return __('privacy_admin.log.navigation_label');
    }

    public function getTitle(): string
    {
        return __('privacy_admin.log.title');
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
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.privacy.log-lookup'),
            EmbeddedTable::make(),
        ]);
    }

    // ---- the lookup --------------------------------------------------------------------------------------------------------------

    public function lookup(): void
    {
        abort_unless(static::canAccess(), 403);

        $email = trim($this->lookupEmail);
        $emailKey = filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : IdentityKeys::email($email);

        if ($emailKey === null) {
            $this->lookupResult = ['state' => 'invalid'];

            return;
        }

        try {
            $hash = (string) app(PrivacyHash::class)->email($emailKey);
        } catch (PrivacyNotConfigured) {
            $this->lookupResult = ['state' => 'unavailable'];

            return;
        }

        $row = DB::table('privacy_requests')->where('kind', PrivacyRequestModel::KIND_ERASE)->where('status', PrivacyRequestModel::STATUS_DONE)->where('email_hash', $hash)
            ->orderByDesc('completed_at')->orderByDesc('id')->first(['completed_at']);

        if ($row === null) {
            $this->lookupResult = ['state' => 'none'];

            return;
        }

        $suppression = DB::table('privacy_suppressions')->where('kind', 'email')->where('hash', $hash)->first(['lifted_at']);

        $this->lookupResult = [
            'state' => 'found',
            'date' => $this->when($row->completed_at),
            'suppressed' => $suppression !== null && $suppression->lifted_at === null && app(SuppressionList::class)->isSuppressed($emailKey),
            'lifted' => $suppression !== null && $suppression->lifted_at !== null ? $this->when($suppression->lifted_at) : null,
        ];
    }

    // ---- the manual steps ---------------------------------------------------------------------------------------------------------

    public function toggleStep(int $id, string $key, bool $checked): void
    {
        abort_unless(static::canAccess(), 403);

        // only a key of the CURRENT list
        if (! in_array($key, array_column($this->steps(), 'key'), true)) {
            return;
        }

        $staffId = app(PanelStaffActor::class)->current()?->getKey();

        DB::transaction(function () use ($id, $key, $checked, $staffId): void {
            $row = PrivacyRequestModel::query()->lockForUpdate()->find($id);

            if ($row === null || $row->kind !== PrivacyRequestModel::KIND_ERASE) {
                return;
            }

            $steps = array_values(array_filter($row->manual_steps ?? [], static fn (mixed $step): bool => ! is_array($step) || ($step['key'] ?? null) !== $key));

            if ($checked) {
                $steps[] = ['key' => $key, 'done_at' => now()->toIso8601String(), 'staff_id' => $staffId === null ? null : (int) $staffId];
            }

            $row->manual_steps = $steps;
            $row->save();
        });
    }

    /** @return list<array{key: string, label: string}> the CURRENT steps, read once per request */
    public function steps(): array
    {
        return $this->stepsCache ??= app(PrivacySettings::class)->manualStepList();
    }

    // ---- the table -----------------------------------------------------------------------------------------------------------------

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => PrivacyRequestModel::query()
                ->where('kind', PrivacyRequestModel::KIND_ERASE)
                ->whereIn('status', [PrivacyRequestModel::STATUS_DONE, PrivacyRequestModel::STATUS_PROCESSING, PrivacyRequestModel::STATUS_FAILED]))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw('COALESCE(completed_at, created_at) DESC')->orderByDesc('id'))
            ->paginated([25])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading(__('privacy_admin.log.empty'))
            ->columns([
                TextColumn::make('completed_at')
                    ->label(__('privacy_admin.log.columns.date'))
                    ->getStateUsing(fn (PrivacyRequestModel $record): string => $this->when($record->completed_at ?? $record->created_at))
                    ->description(fn (PrivacyRequestModel $record): ?string => in_array($record->status, [PrivacyRequestModel::STATUS_PROCESSING, PrivacyRequestModel::STATUS_FAILED], true) ? __('privacy_admin.log.status.'.$record->status) : null),
                ViewColumn::make('contact')
                    ->label(__('privacy_admin.log.columns.contact'))
                    ->view('filament.privacy.log-contact'),
                TextColumn::make('staff_name')
                    ->label(__('privacy_admin.log.columns.staff'))
                    ->placeholder('—'),
                TextColumn::make('reason')
                    ->label(__('privacy_admin.log.columns.reason'))
                    ->placeholder('—')
                    ->wrap(),
                ViewColumn::make('actions_done')
                    ->label(__('privacy_admin.log.columns.actions'))
                    ->view('filament.privacy.log-actions'),
                ViewColumn::make('manual')
                    ->label(__('privacy_admin.log.columns.steps'))
                    ->view('filament.privacy.log-steps'),
                ViewColumn::make('clear')
                    ->label(__('privacy_admin.log.columns.clear'))
                    ->view('filament.privacy.log-clear'),
            ]);
    }

    private function when(mixed $value): string
    {
        return $value === null ? '' : \Illuminate\Support\Carbon::parse($value)->timezone(FilamentTimezone::get())->format('d.m.Y H:i');
    }
}
