<?php

namespace App\Filament\Pages;

use App\Customers\CustomerListQuery;
use App\Customers\CustomerRow;
use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Privacy\Persistence\PrivacyRequestModel;
use App\Privacy\PrivacyHash;
use App\Privacy\PrivacyNotConfigured;
use App\Services\PriceDisplayFormatter;
use BackedEnum;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\Money;
use EasyCo\Staff\Enums\Permission;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/**
 * ACC-4e-1 (acc-4-gdpr-design.md D5, §7): the admin «Клиенти» list: one row per person, accounts and guests together (App\Customers\CustomerListQuery: ONE statement, a constant number
 * of queries per page). `/admin/customers`, Sales group right after «Поръчки». A row opens `/admin/customers/{o-orderId|a-accountId}` (CustomerPage): the URL never carries the email.
 *
 * WHO: Permission::CUSTOMER_VIEW, checked at canAccess() (the navigation and the route) AND on EVERY Livewire request (`boot()`): a table click, a search or a page change by someone
 * who lost the permission is refused, not trusted from the first render.
 *
 * The «Забравен» badge is computed ONLY for the rows of the current page, in one query, through the keyed hash (PrivacyHash); without PRIVACY_HASH_KEY in production there is no badge.
 */
class Customers extends Page implements HasTable
{
    use AuthorizesViaStaffPermission;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $slug = 'customers';

    #[Url(as: 'tab')]
    public string $activeTab = CustomerListQuery::TAB_ALL;

    /** @var array<string, true>|null the email keys of the CURRENT page that have a done erase request */
    private ?array $forgotten = null;

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::SALES;
    }

    /** After «Поръчки» (10); «Нуждае се от внимание» is 20. */
    public static function getNavigationSort(): ?int
    {
        return 15;
    }

    public static function getNavigationLabel(): string
    {
        return __('customers.navigation_label');
    }

    public function getTitle(): string
    {
        return __('customers.title');
    }

    protected static function accessPermission(): ?Permission
    {
        return Permission::CUSTOMER_VIEW;
    }

    public static function canAccess(): bool
    {
        return static::staffCanForAction(static::accessPermission());
    }

    /** Runs on EVERY request of this component, the first one and each Livewire update. */
    public function boot(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function updatedActiveTab(): void
    {
        in_array($this->activeTab, [CustomerListQuery::TAB_ALL, CustomerListQuery::TAB_ACCOUNTS, CustomerListQuery::TAB_GUESTS], true) || $this->activeTab = CustomerListQuery::TAB_ALL;
        $this->resetPage();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.customers.tabs'),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => CustomerListQuery::query(in_array($this->activeTab, [CustomerListQuery::TAB_ACCOUNTS, CustomerListQuery::TAB_GUESTS], true) ? $this->activeTab : CustomerListQuery::TAB_ALL))
            ->defaultSort('last_order_at', 'desc')
            ->paginated([CustomerListQuery::PER_PAGE])
            ->defaultPaginationPageOption(CustomerListQuery::PER_PAGE)
            ->searchPlaceholder(__('customers.search_placeholder'))
            ->recordUrl(fn (CustomerRow $record): string => CustomerPage::getUrl(['key' => $record->row_key]))
            ->emptyStateHeading(__('customers.empty'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('customers.columns.name'))
                    ->placeholder('—')
                    ->limit(16)->tooltip(fn (CustomerRow $record): ?string => $record->name)
                    ->sortable()
                    // the ONE search box: email, name and phone (CustomerListQuery::search); a person is found once
                    ->searchable(query: fn (Builder $query, string $search): Builder => CustomerListQuery::search($query, $search)),
                TextColumn::make('email')
                    ->label(__('customers.columns.email'))
                    ->limit(18)
                    ->tooltip(fn (CustomerRow $record): string => (string) $record->email)
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('customers.email_key', $direction)),
                TextColumn::make('phone')
                    ->label(__('customers.columns.phone'))
                    ->placeholder('—'),
                TextColumn::make('orders_count')
                    ->label(__('customers.columns.orders'))
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('spent_minor')
                    ->label(__('customers.columns.spent'))
                    ->alignEnd()
                    ->sortable()
                    ->getStateUsing(fn (CustomerRow $record): string => $record->currency === null ? '—' : app(PriceDisplayFormatter::class)->format(Money::fromMinorUnits($record->spent_minor, $record->currency)->decimalValue(), Currency::of($record->currency))),
                TextColumn::make('last_order_at')
                    ->label(__('customers.columns.last_order'))
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->sortable(),
                // «Профил» / «Гост», and «Забравен» beside it (the badge of the current page's rows only): one column, so the table fits the page
                TextColumn::make('has_account')
                    ->label(__('customers.columns.profile'))
                    ->badge()
                    ->getStateUsing(fn (CustomerRow $record): array => array_values(array_filter([
                        $record->has_account ? __('customers.badges.account') : __('customers.badges.guest'),
                        isset($this->forgottenEmails()[$record->email_key]) ? __('customers.badges.forgotten') : null,
                    ])))
                    ->color(fn (string $state): string => match ($state) {
                        __('customers.badges.account') => 'success',
                        __('customers.badges.forgotten') => 'warning',
                        default => 'gray',
                    }),
            ]);
    }

    /**
     * The email keys of THIS page's rows that have a `done` erase request, in ONE query by keyed hash. Empty without PRIVACY_HASH_KEY (production): no badge instead of an error.
     *
     * @return array<string, true>
     */
    private function forgottenEmails(): array
    {
        if ($this->forgotten !== null) {
            return $this->forgotten;
        }

        $this->forgotten = [];

        try {
            $hashes = [];

            foreach ($this->getTableRecords() as $record) {
                $hashes[(string) app(PrivacyHash::class)->email($record->email_key)] = $record->email_key;
            }

            if ($hashes === []) {
                return $this->forgotten;
            }

            foreach (DB::table('privacy_requests')->where('kind', PrivacyRequestModel::KIND_ERASE)->where('status', PrivacyRequestModel::STATUS_DONE)->whereIn('email_hash', array_keys($hashes))->pluck('email_hash') as $hash) {
                $this->forgotten[$hashes[$hash]] = true;
            }
        } catch (PrivacyNotConfigured) {
            $this->forgotten = [];
        }

        return $this->forgotten;
    }
}
