<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Services\Exceptions\OrderTransitionRefusedException;
use App\Services\OrderAdminEventView;
use App\Services\OrderAdminOrderView;
use App\Services\OrderAdminReader;
use App\Services\OrderAdminSaleLineView;
use App\Services\OrderPaymentConfirmer;
use App\Services\OrderStatusChanger;
use App\Services\PriceDisplayFormatter;
use App\Services\ProductPriceDisplay;
use BackedEnum;
use Closure;
use DateTimeImmutable;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Exceptions\InvalidOrderTransitionException;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\Money;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\Entry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as RepeatableTableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Lang;
use InvalidArgumentException;

/**
 * Orders — admin-panel-design.md §14, order-lifecycle-design.md §8. NO
 * create/edit/delete PAGES, NO BULK ACTIONS, and STILL NO LIST-PAGE ROW
 * ACTIONS (D1's original read-only posture for the list stands) — but,
 * as of §10 stage 7b, no longer status-transition-free: the View page's
 * header carries the lifecycle's first four write actions (D2 —
 * confirmAction()/shipAction()/deliverAction()/markAsReceivedAction()
 * below), gated Action-by-Action on Permission::ORDER_MANAGE, never
 * through createPermission()/editPermission()/deletePermission(), which
 * stay deliberately unset — AuthorizesViaStaffPermission's own
 * staffCanForAction() fails closed for an undeclared permission (see
 * that trait's own docblock), so canCreate()/canEdit()/canDelete() are
 * still false for every role without a single line of code here;
 * asserted by a real test, not just implied by omission.
 *
 * Gated by Permission::ORDER_VIEW to open either page at all; the four
 * write actions additionally require ORDER_MANAGE. Administrator and
 * Manager hold both, Product Entry holds neither (StaffSystemRolesSeeder).
 *
 * EVERY CROSS-TABLE READ LIVES IN App\Services\OrderAdminReader, NOT
 * HERE — this Resource only wires that service's output into Filament's
 * own Table/Infolist components (admin-panel-design.md §14, D5).
 * OrderModel itself carries no relations (§0's own "package isolation"
 * note) and none are added here.
 */
class OrderResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = OrderModel::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    /**
     * §14's line thumbnail: 38x38 px, square — the merchant's own size for
     * it, named here rather than repeated as a bare number in a cell and in
     * a test.
     */
    private const LINE_THUMBNAIL_SIZE_PX = 38;

    /**
     * The status-view toolbar buttons — mirrors
     * ProductResource::STATUS_VIEWS exactly: the values are real column
     * values for the six real statuses, and the literal 'all' for the view
     * that adds no constraint (statusViewFrom() below), 'all' FIRST rather
     * than last (ARCHITECT DEFAULT, this stage's own brief) — a merchant
     * landing on Orders defaults to seeing everything, unlike Products
     * where the active view is the daily default.
     *
     * SEVEN BUTTONS, NOT FOUR — the one real difference OrderStatus's own
     * six-value shape forces versus Product's three: this toolbar row is
     * visibly longer than Products' own. Nothing here shortens it; §2.1 of
     * order-lifecycle-design.md states six statuses and this table offers
     * exactly that many views plus 'all', the same "no lookup table to
     * drift out of sync" reasoning Product's own docblock gives.
     */
    public const STATUS_VIEWS = [
        'all',
        OrderStatus::PLACED->value,
        OrderStatus::CONFIRMED->value,
        OrderStatus::SHIPPED->value,
        OrderStatus::DELIVERED->value,
        OrderStatus::CANCELLED->value,
        OrderStatus::REFUNDED->value,
    ];

    /** ARCHITECT DEFAULT (this stage's own brief): 'all', not a single status — see STATUS_VIEWS's own docblock. */
    public const STATUS_VIEW_DEFAULT = 'all';

    /**
     * The status view the given Livewire component is showing — byte-for-
     * byte the same shape as ProductResource::statusViewFrom() (see that
     * method's own docblock for the full reasoning: the #[Url] property
     * lives on ListOrders, this is the one whitelisted reader of it, and it
     * self-heals a crafted/stale `?status=…` back onto the property).
     */
    public static function statusViewFrom(mixed $livewire): string
    {
        $livewireHasTheProperty = is_object($livewire) && property_exists($livewire, 'statusView');

        $view = $livewireHasTheProperty ? (string) $livewire->statusView : '';
        $normalized = in_array($view, self::STATUS_VIEWS, true) ? $view : self::STATUS_VIEW_DEFAULT;

        if ($livewireHasTheProperty && $normalized !== $view) {
            $livewire->statusView = $normalized;
        }

        return $normalized;
    }

    /**
     * ONE ActionGroup of colour-toggling buttons — byte-for-byte
     * ProductResource::statusViewButtons()'s own shape.
     */
    public static function statusViewButtons(): ActionGroup
    {
        return ActionGroup::make(array_map(
            static fn (string $view): Action => static::statusViewButton($view),
            self::STATUS_VIEWS,
        ))->buttonGroup();
    }

    /**
     * One status view button. NO clearTableSelection() call, unlike
     * ProductResource::statusViewButton() — a REAL, REPORTED DIFFERENCE:
     * this Resource registers no bulk action anywhere (D1: strictly
     * read-only, no create/edit/delete), so
     * HasBulkActions::isSelectionEnabled() is never true here and there is
     * no selection state a view switch could ever need to clear.
     * resetPage() stays: switching views still changes the row set, and a
     * stale page number is the same real bug Product's own docblock
     * documents finding.
     */
    private static function statusViewButton(string $view): Action
    {
        return Action::make("status_view_{$view}")
            ->label(__("orders.status_views.{$view}"))
            ->color(fn ($livewire): string => static::statusViewFrom($livewire) === $view ? 'primary' : 'gray')
            ->action(function ($livewire) use ($view): void {
                $livewire->statusView = $view;
                $livewire->resetPage();
            });
    }

    public static function getModelLabel(): string
    {
        return __('orders.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('orders.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('orders.navigation_label');
    }

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::SALES;
    }

    public static function getNavigationSort(): ?int
    {
        return 10;
    }

    protected static function viewAnyPermission(): ?Permission
    {
        return Permission::ORDER_VIEW;
    }

    protected static function viewPermission(): ?Permission
    {
        return static::viewAnyPermission();
    }

    /**
     * D2/D4 (order-lifecycle-design.md §8.3 item 1, §10 stage 7b) — the
     * SAME deliberate, arbitrary-permission escape hatch
     * ProductResource::staffHasPermission() already carries, newly added
     * here rather than inherited: AuthorizesViaStaffPermission's own
     * staffCanForAction() is protected on the trait, so a method on THIS
     * Resource can call it but a page (ViewOrder) or any other class
     * cannot — exactly what the four write actions' own ->visible()
     * closures need to check ORDER_MANAGE, a permission this Resource's
     * own createPermission()/editPermission()/deletePermission() never
     * declare (§0/D4: no change to those overrides).
     */
    public static function staffHasPermission(Permission $permission): bool
    {
        return static::staffCanForAction($permission);
    }

    /**
     * Empty on purpose — no Create/Edit page is ever registered (see
     * getPages()), so nothing calls Resource::form() for this Resource
     * in practice; left as an explicit empty schema rather than
     * inherited default behavior, so that stays true even if a future
     * change adds a page that does call it by mistake.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * D1 — the list's five columns, in order: Number (id), Date
     * (placed_at), Recipient (name + email as a description line), Status
     * (the order's OWN status, translated) and Total. Client, Channel,
     * Payment method, Payment status and Item count are gone from the
     * LIST only — every one of them still renders on the View page
     * (channel and payment have their own sections there).
     *
     *  - id: order number (there is no separate human-readable one —
     *    §0's own note), searchable, still part of the sort tie-break.
     *  - placed_at: default sort, descending.
     *  - recipient_name (+ email as a description line): searchable
     *    against email specifically, per the task's own "Search: order
     *    id and email" — recipient_name itself is never matched, only
     *    displayed.
     *  - status: a real `orders` column, so unlike the removed computed
     *    cells it is genuinely, safely sortable.
     *  - total: formatted through the same PriceDisplayFormatter every
     *    other price display in this admin panel already uses.
     *
     * D2 — STATUS-VIEW TOOLBAR BUTTONS (this stage's own revision — see
     * STATUS_VIEWS's own docblock) plus ONE QUICK FILTER rendered as a
     * compact dropdown trigger (FiltersLayout::Dropdown — confirmed a real
     * v5.8.1 enum case, read directly from vendor source before use): Payment
     * method, single-select and clearable, in the SAME header row as the
     * status buttons and the table's own search box, not a separate row
     * beneath (the earlier AboveContent layout's own always-expanded row).
     * A Channel filter was deliberately NOT built — the `orders` table only
     * ever holds online orders, so it would always offer a single value.
     * This Resource supplies only the label and the option list; the reads
     * behind it belong to OrderAdminReader (D3) — the "latest payment row"
     * definition reuses the exact subquery forOrder() uses, and the option
     * list offers only methods that really are some order's latest
     * payment.
     */
    public static function table(Table $table): Table
    {
        return $table
            // Tie-break for two orders with an identical placed_at: NO
            // explicit ->orderBy('id', 'desc') needed here — confirmed
            // against the installed source, not assumed.
            // Filament\Tables\Table\Concerns\CanSortRecords::
            // $hasDefaultKeySort defaults to true, and
            // Filament\Tables\Concerns\CanSortRecords::
            // applySortingToTableQuery() (vendor/filament/tables/src/
            // Concerns/CanSortRecords.php, ~lines 119-140) appends
            // ->orderBy($qualifiedKeyName, $sortDirection) itself whenever
            // the query doesn't already order by the model's key — using
            // the SAME $sortDirection as this defaultSort ('desc'), so the
            // real tie-break is already "placed_at desc, then id desc".
            ->defaultSort('placed_at', 'desc')
            // THE STATUS VIEW IS THE ONLY STATUS FILTER ON THIS LIST (D2) —
            // byte-for-byte ProductResource::table()'s own
            // ->modifyQueryUsing() shape (see that method's own docblock:
            // $livewire injected BY NAME, a real, confirmed mechanism).
            // 'all' is the only view that adds no constraint.
            ->modifyQueryUsing(function (Builder $query, $livewire): Builder {
                $statusView = static::statusViewFrom($livewire);

                if ($statusView !== 'all') {
                    $query->where('status', $statusView);
                }

                return $query;
            })
            ->columns([
                TextColumn::make('id')
                    ->label(__('orders.fields.id'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('placed_at')
                    ->label(__('orders.fields.placed_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('recipient_name')
                    ->label(__('orders.fields.recipient_name'))
                    ->description(fn (OrderModel $record): string => $record->email)
                    // Searches the real 'email' column, not
                    // recipient_name itself — the task's own explicit
                    // "search: order id and email", nothing else.
                    ->searchable(['orders.email']),
                TextColumn::make('status')
                    ->label(__('orders.fields.status'))
                    ->formatStateUsing(fn (?string $state): string => static::optionLabel('status', $state)),
                TextColumn::make('total')
                    ->label(__('orders.fields.total'))
                    ->getStateUsing(fn (OrderModel $record): string => static::formatOrderMoney($record, 'total_minor')),
            ])
            ->filters([
                SelectFilter::make('payment_method')
                    ->label(__('orders.fields.payment_method'))
                    ->placeholder(__('orders.filters.all_payment_methods'))
                    // Only methods that are some order's LATEST payment —
                    // every option is guaranteed to match at least one row
                    // (OrderAdminReader::paymentMethodOptions()).
                    ->options(static::paymentMethodFilterOptions())
                    // blank() covers both "never chosen" and "cleared" —
                    // returning the query untouched is what makes clearing
                    // the filter restore the full list.
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : app(OrderAdminReader::class)->applyLatestPaymentMethodFilter($query, (string) $data['value'])),
            ], layout: FiltersLayout::Dropdown)
            // REPORTED DIFFERENCE FROM THIS STAGE'S OWN BRIEF: placed via
            // ->toolbarActions(), not ->headerActions() — verified directly
            // against ProductResource::table(), which places its own
            // statusViewButtons() call inside ->toolbarActions() (that
            // method's own docblock walks vendor/filament's container.css
            // rule for why that renders in the table's own header-toolbar
            // row alongside search/filters). No BulkActionGroup sits beside
            // it here — this Resource registers no bulk action at all
            // (D1), unlike Product's own toolbarActions() call.
            ->toolbarActions([
                static::statusViewButtons(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }

    /**
     * D3's seven sections, built entirely from ONE
     * OrderAdminReader::forOrder() read — its own per-instance cache
     * (see that class's own docblock) means every closure below calling
     * app(OrderAdminReader::class)->forOrder((string) $record->id)
     * resolves the SAME already-fetched OrderAdminOrderView, not a
     * fresh re-read per Section/Entry.
     *
     * D4: profit is not read from OrderAdminOrderView anywhere below —
     * it is not even a field OrderAdminSaleLineView exposes (see that
     * DTO's own docblock) — nothing here could show it by accident.
     *
     * "Account link if any" (the task's own §14 wording) renders as
     * plain text, not a real hyperlink — no AccountResource exists yet
     * in this admin panel to link to. Flagged, not silently
     * downgraded.
     *
     * ONE COLUMN, DELIBERATELY — the sections stack, they are never paired
     * side by side. Filament's default section grid goes to TWO columns from
     * the `lg` breakpoint, and with two columns a tall section (the lines
     * table) sat beside a short one and left a large empty gap under the
     * short one before the next section began (found in the panel itself:
     * Promotion and Payment appeared far below Delivery). The single column
     * is set on THIS schema; each Section's own entries still lay out in
     * their own 3- or 4-column grid.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('orders.sections.summary'))
                ->schema([
                    TextEntry::make('id')
                        ->label(__('orders.fields.id')),
                    TextEntry::make('placed_at')
                        ->label(__('orders.fields.placed_at'))
                        ->dateTime(),
                    TextEntry::make('status')
                        ->label(__('orders.fields.status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => __("orders.status_options.{$state}")),
                    TextEntry::make('channel')
                        ->label(__('orders.fields.channel'))
                        ->getStateUsing(fn (OrderModel $record): string => static::optionLabel('channel', static::forOrder($record)->channel)),
                ])
                ->columns(4),
            Section::make(__('orders.sections.client'))
                ->schema([
                    TextEntry::make('client_name')
                        ->label(__('orders.fields.client_name'))
                        ->getStateUsing(fn (OrderModel $record): string => static::forOrder($record)->clientName),
                    TextEntry::make('client_id')
                        ->label(__('orders.fields.client_id'))
                        ->getStateUsing(fn (OrderModel $record): string => static::forOrder($record)->order->clientId()),
                    TextEntry::make('email')
                        ->label(__('orders.fields.email')),
                    TextEntry::make('phone')
                        ->label(__('orders.fields.phone')),
                    TextEntry::make('account_id')
                        ->label(__('orders.fields.account_id'))
                        ->formatStateUsing(fn (?string $state): string => $state ?? __('orders.not_available')),
                ])
                ->columns(3),
            Section::make(__('orders.sections.delivery'))
                ->schema([
                    TextEntry::make('delivery_type')
                        ->label(__('orders.fields.delivery_type'))
                        ->formatStateUsing(fn (string $state): string => __("orders.delivery_type_options.{$state}")),
                    TextEntry::make('country')
                        ->label(__('orders.fields.country'))
                        ->visible(fn (OrderModel $record): bool => $record->delivery_type === OrderDeliveryType::STREET_ADDRESS->value)
                        ->formatStateUsing(fn (?string $state): string => $state ?? __('orders.not_available')),
                    TextEntry::make('city')
                        ->label(__('orders.fields.city'))
                        ->visible(fn (OrderModel $record): bool => $record->delivery_type === OrderDeliveryType::STREET_ADDRESS->value)
                        ->formatStateUsing(fn (?string $state): string => $state ?? __('orders.not_available')),
                    TextEntry::make('postal_code')
                        ->label(__('orders.fields.postal_code'))
                        ->visible(fn (OrderModel $record): bool => $record->delivery_type === OrderDeliveryType::STREET_ADDRESS->value)
                        ->formatStateUsing(fn (?string $state): string => $state ?? __('orders.not_available')),
                    TextEntry::make('address_line_1')
                        ->label(__('orders.fields.address_line_1'))
                        ->visible(fn (OrderModel $record): bool => $record->delivery_type === OrderDeliveryType::STREET_ADDRESS->value)
                        ->formatStateUsing(fn (?string $state): string => $state ?? __('orders.not_available')),
                    TextEntry::make('address_line_2')
                        ->label(__('orders.fields.address_line_2'))
                        ->visible(fn (OrderModel $record): bool => $record->delivery_type === OrderDeliveryType::STREET_ADDRESS->value
                            && $record->address_line_2 !== null),
                    TextEntry::make('carrier_code')
                        ->label(__('orders.fields.carrier_code'))
                        ->visible(fn (OrderModel $record): bool => $record->delivery_type === OrderDeliveryType::PICKUP_POINT->value),
                    TextEntry::make('pickup_point_reference')
                        ->label(__('orders.fields.pickup_point_reference'))
                        ->visible(fn (OrderModel $record): bool => $record->delivery_type === OrderDeliveryType::PICKUP_POINT->value),
                    TextEntry::make('settlement')
                        ->label(__('orders.fields.settlement'))
                        ->visible(fn (OrderModel $record): bool => $record->delivery_type === OrderDeliveryType::PICKUP_POINT->value),
                ])
                ->columns(3),
            Section::make(__('orders.sections.lines'))
                ->schema([
                    RepeatableEntry::make('lines')
                        ->hiddenLabel()
                        ->getStateUsing(fn (OrderModel $record): array => static::lineRows($record))
                        ->table(fn (OrderModel $record): array => array_map(
                            // Same rule as the cells themselves: a column
                            // whose line carries a name is a NUMBER column,
                            // so its heading is end-aligned with it — only
                            // visible in the wide/table mode, where the
                            // stacked mode's own labels give way to this
                            // header row.
                            static fn (array $spec): RepeatableTableColumn => RepeatableTableColumn::make($spec['label'])
                                ->alignEnd(static::lineValueLabel($spec['key']) !== null),
                            static::lineColumnSpecs($record),
                        ))
                        ->schema(fn (OrderModel $record): array => array_map(
                            static fn (array $spec): Entry => static::lineCell($spec['key']),
                            static::lineColumnSpecs($record),
                        )),
                ]),
            Section::make(__('orders.sections.promotion'))
                ->schema([
                    TextEntry::make('applied_promotion_code')
                        ->label(__('orders.fields.promotion_code'))
                        ->formatStateUsing(fn (?string $state): string => $state ?? __('orders.no_promotion')),
                    TextEntry::make('discount_minor')
                        ->label(__('orders.fields.discount'))
                        ->visible(fn (OrderModel $record): bool => $record->applied_promotion_code !== null)
                        ->getStateUsing(fn (OrderModel $record): string => static::formatOrderMoney($record, 'discount_minor')),
                    TextEntry::make('promotion_redeemed')
                        ->label(__('orders.fields.promotion_redeemed'))
                        ->visible(fn (OrderModel $record): bool => $record->applied_promotion_code !== null)
                        ->getStateUsing(fn (OrderModel $record): string => static::forOrder($record)->hasPromotionRedemption
                            ? __('orders.yes')
                            : __('orders.no')),
                ])
                ->columns(3),
            Section::make(__('orders.sections.totals'))
                ->schema([
                    TextEntry::make('subtotal_minor')
                        ->label(__('orders.fields.subtotal'))
                        ->getStateUsing(fn (OrderModel $record): string => static::formatOrderMoney($record, 'subtotal_minor')),
                    TextEntry::make('discount_minor_total')
                        ->label(__('orders.fields.discount'))
                        ->getStateUsing(fn (OrderModel $record): string => static::formatOrderMoney($record, 'discount_minor')),
                    TextEntry::make('total_minor')
                        ->label(__('orders.fields.total'))
                        ->getStateUsing(fn (OrderModel $record): string => static::formatOrderMoney($record, 'total_minor')),
                ])
                ->columns(3),
            Section::make(__('orders.sections.payment'))
                ->schema([
                    TextEntry::make('payment_method')
                        ->label(__('orders.fields.payment_method'))
                        ->getStateUsing(function (OrderModel $record): string {
                            $payment = static::forOrder($record)->latestPayment;

                            return $payment !== null
                                ? static::optionLabel('payment_method', $payment->method())
                                : __('orders.no_payment');
                        }),
                    TextEntry::make('payment_status')
                        ->label(__('orders.fields.payment_status'))
                        ->badge()
                        ->visible(fn (OrderModel $record): bool => static::forOrder($record)->latestPayment !== null)
                        // Not just a defensive null-check — getStateUsing()
                        // closures are not guaranteed to be skipped just
                        // because ->visible() returned false (Filament
                        // may still evaluate state internally), so this
                        // reads defensively rather than trusting the
                        // sibling ->visible() call above alone.
                        ->getStateUsing(function (OrderModel $record): string {
                            $payment = static::forOrder($record)->latestPayment;

                            return $payment !== null
                                ? static::optionLabel('payment_status', $payment->status()->value)
                                : __('orders.no_payment');
                        }),
                    TextEntry::make('provider_reference')
                        ->label(__('orders.fields.provider_reference'))
                        ->visible(fn (OrderModel $record): bool => static::forOrder($record)->latestPayment?->providerReference() !== null)
                        ->getStateUsing(fn (OrderModel $record): ?string => static::forOrder($record)->latestPayment?->providerReference()),
                    TextEntry::make('failure_reason')
                        ->label(__('orders.fields.failure_reason'))
                        ->visible(fn (OrderModel $record): bool => static::forOrder($record)->latestPayment?->failureReason() !== null)
                        ->getStateUsing(fn (OrderModel $record): ?string => static::forOrder($record)->latestPayment?->failureReason()),
                    TextEntry::make('attempted_at')
                        ->label(__('orders.fields.attempted_at'))
                        ->visible(fn (OrderModel $record): bool => static::forOrder($record)->latestPayment?->attemptedAt() !== null)
                        ->getStateUsing(fn (OrderModel $record): ?string => static::forOrder($record)->latestPayment?->attemptedAt()?->format('Y-m-d H:i')),
                    TextEntry::make('payment_attempts')
                        ->label(__('orders.fields.payment_attempts'))
                        ->visible(fn (OrderModel $record): bool => static::forOrder($record)->paymentAttemptCount > 1)
                        ->getStateUsing(fn (OrderModel $record): string => __('orders.attempts_suffix', ['count' => static::forOrder($record)->paymentAttemptCount])),
                ])
                // D3 (tightened): columns(4), matching Summary's own
                // density (id/placed_at/status/channel, one row of 4) —
                // not one fact per full-width row.
                ->columns(4),
            Section::make(__('orders.sections.history'))
                ->schema([
                    RepeatableEntry::make('history')
                        ->hiddenLabel()
                        ->getStateUsing(fn (OrderModel $record): array => static::historyRows($record))
                        ->table(array_map(
                            static fn (array $spec): RepeatableTableColumn => RepeatableTableColumn::make($spec['label']),
                            static::historyColumnSpecs(),
                        ))
                        ->schema(array_map(
                            static fn (array $spec): Entry => TextEntry::make($spec['key'])->hiddenLabel(),
                            static::historyColumnSpecs(),
                        )),
                ]),
        ])->columns(1);
    }

    /**
     * D2 (order-lifecycle-design.md §8, §10 stage 7b) — the View page's
     * FIRST write buttons, mounted from ViewOrder::getHeaderActions() in
     * this order: Confirm, Ship, Deliver, "Mark as received". Each is
     * gated by ORDER_MANAGE (D4: Action-level only, no change to this
     * Resource's own permission overrides) and its own status/eligibility
     * condition, collects an optional note in the SAME confirmation modal
     * (Filament v5.8.1's real API is ->schema(), confirmed by reading
     * vendor/filament/actions/src/Concerns/HasSchema.php directly —
     * ->form() there is `@deprecated Use schema() instead`, a thin wrapper
     * around the very same method), and shares one refusal/success/redirect
     * shape via runOrderAction() below.
     *
     * D3: Ship's own ->visible() is `status === confirmed` ONLY — R9's
     * guard is surfaced by the ATTEMPT (a translated failure), never by
     * hiding the button, so a bank_transfer order whose payment has not
     * settled still offers Ship and tells the merchant why it refused.
     */
    public static function confirmAction(): Action
    {
        return Action::make('confirm')
            ->label(__('orders.actions.confirm'))
            ->color('primary')
            ->icon('heroicon-o-check-circle')
            ->requiresConfirmation()
            ->modalHeading(fn (OrderModel $record): string => __('orders.actions.confirm_heading', ['id' => $record->id]))
            ->modalDescription(fn (OrderModel $record): string => __('orders.actions.confirm_description', ['id' => $record->id]))
            ->schema([
                Textarea::make('note')->label(__('orders.actions.note_label')),
            ])
            ->visible(fn (OrderModel $record): bool => static::staffHasPermission(Permission::ORDER_MANAGE)
                && $record->status === OrderStatus::PLACED->value)
            ->action(function (array $data, OrderModel $record, $livewire): void {
                static::runOrderAction(
                    $record,
                    $livewire,
                    fn () => app(OrderStatusChanger::class)->confirm((string) $record->id, new DateTimeImmutable(), $data['note'] ?? null),
                    __('orders.actions.confirm_done'),
                );
            });
    }

    public static function shipAction(): Action
    {
        return Action::make('ship')
            ->label(__('orders.actions.ship'))
            ->color('warning')
            ->icon('heroicon-o-truck')
            ->requiresConfirmation()
            ->modalHeading(fn (OrderModel $record): string => __('orders.actions.ship_heading', ['id' => $record->id]))
            ->modalDescription(fn (OrderModel $record): string => __('orders.actions.ship_description', ['id' => $record->id]))
            ->schema([
                Textarea::make('note')->label(__('orders.actions.note_label')),
            ])
            // D3: no ADDITIONAL gate on isSettled() here — every confirmed
            // order offers Ship, regardless of R9's own guard.
            ->visible(fn (OrderModel $record): bool => static::staffHasPermission(Permission::ORDER_MANAGE)
                && $record->status === OrderStatus::CONFIRMED->value)
            ->action(function (array $data, OrderModel $record, $livewire): void {
                static::runOrderAction(
                    $record,
                    $livewire,
                    fn () => app(OrderStatusChanger::class)->ship((string) $record->id, new DateTimeImmutable(), $data['note'] ?? null),
                    __('orders.actions.ship_done'),
                );
            });
    }

    public static function deliverAction(): Action
    {
        return Action::make('deliver')
            ->label(__('orders.actions.deliver'))
            ->color('success')
            ->icon('heroicon-o-check-badge')
            ->requiresConfirmation()
            ->modalHeading(fn (OrderModel $record): string => __('orders.actions.deliver_heading', ['id' => $record->id]))
            ->modalDescription(fn (OrderModel $record): string => __('orders.actions.deliver_description', ['id' => $record->id]))
            ->schema([
                Textarea::make('note')->label(__('orders.actions.note_label')),
            ])
            ->visible(fn (OrderModel $record): bool => static::staffHasPermission(Permission::ORDER_MANAGE)
                && $record->status === OrderStatus::SHIPPED->value)
            ->action(function (array $data, OrderModel $record, $livewire): void {
                static::runOrderAction(
                    $record,
                    $livewire,
                    fn () => app(OrderStatusChanger::class)->deliver((string) $record->id, new DateTimeImmutable(), $data['note'] ?? null),
                    __('orders.actions.deliver_done'),
                );
            });
    }

    /**
     * "Mark as received" — OrderPaymentConfirmer::confirm(), not
     * OrderStatusChanger (§4.3: confirming a payment is not a transition).
     * No note field (that service's own confirm() takes none). Visible
     * only when the order's CURRENT latest payment is itself confirmable
     * (Payment::isConfirmable(), D1) — read via the SAME forOrder() call
     * every other closure on this page already makes, so this adds no
     * second query (T5).
     */
    public static function markAsReceivedAction(): Action
    {
        return Action::make('mark_as_received')
            ->label(__('orders.actions.mark_as_received'))
            ->color('success')
            ->icon('heroicon-o-banknotes')
            ->requiresConfirmation()
            ->modalHeading(fn (OrderModel $record): string => __('orders.actions.mark_as_received_heading', ['id' => $record->id]))
            ->modalDescription(fn (OrderModel $record): string => __('orders.actions.mark_as_received_description', ['id' => $record->id]))
            ->visible(fn (OrderModel $record): bool => static::staffHasPermission(Permission::ORDER_MANAGE)
                && (static::forOrder($record)->latestPayment?->isConfirmable() ?? false))
            ->action(function (OrderModel $record, $livewire): void {
                // A GRACEFUL RACE GUARD, NOT A FATAL ERROR: the button's
                // own ->visible() read this same fact at render time: by
                // the click it may no longer hold (another operator acted
                // first) — refused with a notification, exactly like every
                // other refusal on this page, never an uncaught error.
                $payment = static::forOrder($record)->latestPayment;

                if ($payment === null || ! $payment->isConfirmable()) {
                    Notification::make()
                        ->title(__('orders.actions.refused_title'))
                        ->body(__('orders.actions.no_eligible_payment'))
                        ->danger()
                        ->send();

                    return;
                }

                static::runOrderAction(
                    $record,
                    $livewire,
                    fn () => app(OrderPaymentConfirmer::class)->confirm($payment->id(), new DateTimeImmutable()),
                    __('orders.actions.mark_as_received_done'),
                );
            });
    }

    /**
     * The shape every action above shares (§8.3 items 4-5): run the
     * domain call, and either translate its refusal or show success and
     * move on. `\Throwable` is deliberately NOT caught here — a single-
     * record action has no "other rows" to protect, so an unexpected
     * defect propagates loudly instead of being flattened into a friendly
     * toast (§8.3 item 4's own reasoning, quoted there for the bulk case
     * this departs from).
     *
     * REFUSAL RENDERING, per exception:
     *  - OrderTransitionRefusedException (R9 today): rendered through the
     *    SAME orders.refusal_reasons.* group R9's own lang entry already
     *    provides — never a second, hand-rolled message for the same fact.
     *  - InvalidOrderTransitionException: a clear sentence built from its
     *    own from()/to() values (both real OrderStatus), translated
     *    through the SAME status_options group the rest of this page
     *    already uses — never its raw English message.
     *  - InvalidArgumentException (an order/payment that vanished, or an
     *    anomaly guard): a clear, honest, translated sentence naming the
     *    order — never its raw message either.
     *
     * ON SUCCESS: order-lifecycle-design.md §8.3 item 5's own answer to
     * "how does the page reflect the new state" — a REDIRECT to the
     * order's own View URL, not a soft Livewire refresh. That section
     * argues why: OrderAdminReader is bound scoped() and memoizes per
     * instance (confirmed against AppServiceProvider.php), so the SAME
     * request that just wrote would otherwise render the header, the
     * status badge and the History section from the pre-write read. A
     * redirect is a new request, a fresh scoped reader, and a correct page
     * for free — the same $livewire->redirect() mechanism
     * ProductResource::duplicateAction() already uses for its own
     * post-write navigation.
     */
    private static function runOrderAction(OrderModel $record, $livewire, Closure $operation, string $successTitle): void
    {
        try {
            $operation();
        } catch (OrderTransitionRefusedException $e) {
            Notification::make()
                ->title(__('orders.actions.refused_title'))
                ->body(__('orders.refusal_reasons.'.$e->reason()->value))
                ->danger()
                ->send();

            return;
        } catch (InvalidOrderTransitionException $e) {
            Notification::make()
                ->title(__('orders.actions.refused_title'))
                ->body(__('orders.actions.invalid_transition_body', [
                    'id' => $record->id,
                    'from' => static::optionLabel('status', $e->from()->value),
                    'to' => static::optionLabel('status', $e->to()->value),
                ]))
                ->danger()
                ->send();

            return;
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title(__('orders.actions.refused_title'))
                ->body(__('orders.actions.generic_refusal_body', ['id' => $record->id]))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title($successTitle)
            ->success()
            ->send();

        $livewire->redirect(static::getUrl('view', ['record' => $record->id]));
    }

    /** Shared by every infolist closure above — one read per record, per OrderAdminReader's own per-instance cache. */
    private static function forOrder(OrderModel $record): OrderAdminOrderView
    {
        return app(OrderAdminReader::class)->forOrder((string) $record->id);
    }

    /**
     * The one formatting shape every money column/entry on this Resource
     * shares: a *_minor column on OrderModel plus $record->currency, run
     * through the same PriceDisplayFormatter every other price display in
     * this admin panel uses. No behaviour change from extracting this —
     * every call site below produced byte-identical output before.
     */
    private static function formatOrderMoney(OrderModel $record, string $minorField): string
    {
        return app(PriceDisplayFormatter::class)->format(
            Money::fromMinorUnits((int) $record->{$minorField}, $record->currency)->decimalValue(),
            Currency::of($record->currency)
        );
    }

    /**
     * Shared by every enum-backed column/entry (channel — EasyCo\
     * OperationalSales\Enums\Channel, payment_method, payment_status —
     * EasyCo\Payment\Enums\PaymentStatus) on both the list and the View
     * page — a real, confirmed gap found before this helper existed: every
     * call site independently ran __("orders.{$group}_options.{$state}")
     * with no Lang::has() check first, and Laravel's own __() returns the
     * translation KEY ITSELF when no entry exists (confirmed against
     * installed source, not assumed) — so a stored value with no lang
     * entry rendered as the literal string "orders.payment_method_options.
     * xyz" instead of the raw value a merchant/support agent could
     * actually recognise. $state === null stays a separate case
     * (orders.not_available, "no value recorded"), never confused with
     * "a value exists but has no label" (this method's own fallback).
     */
    private static function optionLabel(string $group, ?string $state): string
    {
        if ($state === null) {
            return __('orders.not_available');
        }

        $key = "orders.{$group}_options.{$state}";

        return Lang::has($key) ? __($key) : $state;
    }

    /**
     * D2's filter option list — value => label. The VALUES come from
     * OrderAdminReader (the one place every cross-table read for this
     * section lives, D3); the LABELS come from optionLabel() above, so a
     * method with no translation entry shows its raw value here exactly as
     * it does everywhere else on this Resource.
     *
     * @return array<string, string>
     */
    private static function paymentMethodFilterOptions(): array
    {
        return static::labelledOptions(app(OrderAdminReader::class)->paymentMethodOptions(), 'payment_method');
    }

    /**
     * @param string[] $values
     * @return array<string, string>
     */
    private static function labelledOptions(array $values, string $group): array
    {
        $options = [];

        foreach ($values as $value) {
            $options[(string) $value] = static::optionLabel($group, (string) $value);
        }

        return $options;
    }

    /**
     * The row data behind every Lines-table cell, keyed to match
     * lineColumnSpecs()'s own keys. The values for the two HTML cells
     * (product_name, unit_price) are already-escaped markup; every other
     * value is plain text Filament escapes itself. One OrderAdminReader
     * read backs the whole table — no per-line query.
     *
     * line_total and unit_cost are deliberately NOT among these keys: the
     * first repeated Price x Quantity, Discount and Final price, and the
     * second was removed for every role — see lineColumnSpecs()'s own
     * docblock for both reasons. The view object still CARRIES both
     * values (OrderAdminSaleLineView); this page simply stops showing
     * them.
     *
     * `image` IS NULLABLE, unlike every other value here: the line's
     * thumbnail is live data (no snapshot stores one) and a line with no
     * usable photo legitimately has none — lineCell() hides the cell
     * entirely for it rather than rendering a broken image.
     *
     * The four numbers a merchant reads off a line are NAMED on the line
     * itself as well as in the header row, by lineCell() — see its own
     * docblock; the values here stay pure values.
     *
     * @return array<int, array<string, string|null>>
     */
    private static function lineRows(OrderModel $record): array
    {
        return array_map(
            fn (OrderAdminSaleLineView $line): array => [
                'image' => $line->imagePath,
                'product_name' => static::lineProductHtml($line),
                'sku' => $line->sku ?? __('orders.not_available'),
                'quantity' => (string) $line->quantity,
                'unit_price' => static::lineUnitPriceHtml($line),
                // D5: a legacy line's net is NEVER derived from the
                // order-level discount — it renders '—' (unknown), and so
                // does its promotion share, which it never had.
                'promotion_discount' => static::formatLineMoney($line->isLegacy ? null : $line->promotionDiscountShare),
                'discretionary_discount' => static::formatLineMoney($line->discretionaryDiscount),
                'net_paid' => static::formatLineMoney($line->isLegacy ? null : $line->netPaidAmount),
            ],
            static::forOrder($record)->lines,
        );
    }

    /**
     * The Lines table's own column set, in order — ONE list drives both
     * the header row (->table()) and the per-row cells (->schema()), so
     * the two can never drift out of positional alignment:
     * RepeatableEntry maps the Nth cell to the Nth column.
     *
     * The price columns read, in order, Price (the sold unit price, its
     * struck regular price kept exactly as it was), Discount (the line's
     * own promotion share), Merchant discount (a register discount — D4:
     * shown only when some line on this order actually has one) and Final
     * price (net paid). Labels live in lang/{bg,en}/orders.php.
     *
     * TWO COLUMNS ARE DELIBERATELY ABSENT, both removed on purpose:
     *
     *  - unit cost, for EVERY role, Administrator included — COST_VIEW no
     *    longer affects this page at all. A per-line cost on an order
     *    screen is margin analysis by another name, and that belongs to a
     *    future reports screen holding REPORT_VIEW *and* COST_VIEW
     *    explicitly (staff-access-domain-design.md §6), not to a page
     *    gated by ORDER_VIEW alone. The value is untouched — it stays in
     *    the sale line's §3.13 snapshot (a return reverses profit from it)
     *    and in OrderAdminSaleLineView — so nothing here narrows what a
     *    report can read later.
     *  - line total (the amount before discounts): with Price x Quantity,
     *    Discount and Final price it only repeated information.
     *
     * A THUMBNAIL COMES FIRST, before the product name — 38x38 px, the
     * merchant's own size for it (LINE_THUMBNAIL_SIZE_PX), so a line reads
     * like the physical article rather than as a wall of values. It is the
     * only LIVE value on this page (no snapshot stores an image — see
     * OrderAdminReader::imagePathsFor()), and it fails soft: a line with no
     * usable photo renders no image cell at all.
     *
     * @return array<int, array{key: string, label: string}>
     */
    private static function lineColumnSpecs(OrderModel $record): array
    {
        $specs = [
            ['key' => 'image', 'label' => __('orders.fields.image')],
            ['key' => 'product_name', 'label' => __('orders.fields.product_name')],
            ['key' => 'sku', 'label' => __('orders.fields.sku')],
            ['key' => 'quantity', 'label' => __('orders.fields.quantity')],
            ['key' => 'unit_price', 'label' => __('orders.fields.unit_price')],
            ['key' => 'promotion_discount', 'label' => __('orders.fields.promotion_discount')],
        ];

        // D4: the discretionary (register) discount column appears only
        // when at least one line on THIS order actually carries a non-zero
        // value — web checkout always writes zero, so it does not clutter
        // the table for the common case.
        if (static::hasDiscretionaryDiscount(static::forOrder($record))) {
            $specs[] = ['key' => 'discretionary_discount', 'label' => __('orders.fields.discretionary_discount')];
        }

        $specs[] = ['key' => 'net_paid', 'label' => __('orders.fields.net_paid')];

        return $specs;
    }

    /** D4's own "show the discretionary column only if any line has one" check. */
    private static function hasDiscretionaryDiscount(OrderAdminOrderView $view): bool
    {
        foreach ($view->lines as $line) {
            if ($line->discretionaryDiscount !== null && $line->discretionaryDiscount->minorValue() !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * One body cell. product_name and unit_price carry deliberate,
     * already-escaped markup (the sold attributes; the D3 struck price),
     * so they render as HTML — every other cell is plain text, escaped by
     * Filament. See lineRows() for what each key's value is.
     *
     * §14's LINE LABELS live here, not in lineRows(): the four numbers a
     * merchant reads off a line carry their own name as the entry's OWN
     * label, and their value is END-ALIGNED — so every line's numbers sit
     * in one right-hand column, the way amounts read on a receipt, instead
     * of trailing their labels at the left. lineRows() keeps returning pure
     * values; naming and aligning a value are presentation decisions of its
     * cell.
     *
     * Why the entry's label rather than a prefix on the value: Filament
     * renders a table repeatable as a stacked card below its container
     * breakpoint — the header row is `hidden` there (repeatable.css), and
     * the label column is exactly the slot that shows a name in that mode
     * while the CSS hides it again in the wide/table mode, where the header
     * row takes over.
     *
     * THE THUMBNAIL IS THE ONE CELL THAT IS NOT A TextEntry — and the only
     * one hidden outright when it has nothing to show, because an image
     * cell with an empty state would render an empty <img> box that reads
     * as a broken picture (lineRows() explains why its value is nullable).
     */
    private static function lineCell(string $key): Entry
    {
        if ($key === 'image') {
            return ImageEntry::make($key)
                ->label(__('orders.fields.image'))
                // Same disk rule as ProductResource's own list thumbnail —
                // config, never a hardcoded 'public'.
                ->disk(config('services.media.default_disk', 'public'))
                ->imageSize(self::LINE_THUMBNAIL_SIZE_PX)
                ->square()
                ->hidden(fn (?string $state): bool => blank($state));
        }

        $entry = TextEntry::make($key);

        if ($key === 'product_name' || $key === 'unit_price') {
            $entry->html();
        }

        $label = static::lineValueLabel($key);

        if ($label === null) {
            return $entry->hiddenLabel();
        }

        return $entry
            ->label($label)
            ->inlineLabel()
            ->alignEnd();
    }

    /**
     * The name a line puts on one of its own values, in the words the
     * merchant uses for them (admin-panel-design.md §14) — or null for the
     * values a line does NOT name:
     *
     *  - image / product_name / sku are identified by their own content (the
     *    photo, the product name, the SKU), not by a label;
     *  - discretionary_discount (a register discount, D4) appears only when
     *    some line has one, and keeps its own column header only — adding a
     *    fifth label for it was not asked for.
     */
    private static function lineValueLabel(string $key): ?string
    {
        return match ($key) {
            'quantity' => __('orders.line_labels.quantity'),
            'unit_price' => __('orders.line_labels.unit_price'),
            'promotion_discount' => __('orders.line_labels.discount'),
            'net_paid' => __('orders.line_labels.amount'),
            default => null,
        };
    }

    /**
     * D4: the product name, with the sold variation attributes underneath
     * as `Name: value` pairs in their stored order. D5: a legacy line
     * appends a small translated note saying it predates the full
     * snapshot. Every interpolated piece is escaped here because this
     * cell renders as HTML.
     */
    private static function lineProductHtml(OrderAdminSaleLineView $line): string
    {
        $html = e($line->productName ?? __('orders.not_available'));

        foreach ($line->soldAttributes as $attribute) {
            $html .= '<br>'.e(($attribute['definitionName'] ?? '').': '.($attribute['value'] ?? ''));
        }

        if ($line->isLegacy) {
            $html .= '<br><span>'.e(__('orders.legacy_line_note')).'</span>';
        }

        return $html;
    }

    /**
     * D4: the sold regular/final unit price through the one shared markup
     * rule (ProductPriceDisplay::priceHtml()) — the regular price is
     * struck through only when it differs from the final one. D5: a legacy
     * line has no such snapshot, so it keeps the derived amount/quantity
     * with no struck price at all.
     */
    private static function lineUnitPriceHtml(OrderAdminSaleLineView $line): string
    {
        if (! $line->isLegacy && $line->regularUnitPrice !== null && $line->finalUnitPrice !== null) {
            return app(ProductPriceDisplay::class)->priceHtml($line->regularUnitPrice, $line->finalUnitPrice);
        }

        return static::formatLineMoney($line->unitPrice);
    }

    /** '—' for a genuinely absent value, otherwise the shared money formatter — never a second one. */
    private static function formatLineMoney(?Money $money): string
    {
        if ($money === null) {
            return __('orders.not_available');
        }

        return app(PriceDisplayFormatter::class)->format($money->decimalValue(), $money->currency());
    }

    /**
     * D4 — the order's own always-on history (order-lifecycle-design.md
     * §6.3, §10 stage 7), THIS SECTION'S FIRST RENDERING: OrderAdminOrderView::
     * $events already existed with "NOTHING RENDERS IT YET" in its own
     * docblock (that class's own note) — this is the consumer.
     *
     * RENDERED THE SAME TABLE WAY Lines renders its own RepeatableEntry
     * (lineRows()/lineColumnSpecs() above), not stacked/labelled-field-per-
     * event — one row per order_events row, oldest first (the read's own
     * order, unchanged here).
     *
     * A FIXED COLUMN SET, UNLIKE Lines' OWN CONDITIONAL discretionary_discount
     * COLUMN — nothing here varies per record, so historyColumnSpecs() takes
     * no $record and neither ->table() nor ->schema() in infolist() needs a
     * per-record closure the way lineColumnSpecs()'s callers do.
     *
     * SIX COLUMNS: Date (occurred_at), Event (type, via the SAME
     * event_type_options group the label-parity test already pins),
     * From/To (from_status/to_status, via the SAME status_options group the
     * list's own status column uses — both '—' for the four event types
     * that move no status, §6.1), Reason (the operator's own words,
     * verbatim, '—' when none was given) and By (staff_name, or 'System' for
     * a console/job caller — OrderAdminEventView's own docblock already
     * names this exact wording as the View page's job).
     *
     * transaction_id IS DELIBERATELY NOT A COLUMN — no admin surface reads
     * or links to a Transaction yet (no TransactionResource exists), so a
     * raw id would be noise nobody here can act on; OrderAdminEventView
     * still carries it for whenever that changes.
     *
     * @return array<int, array<string, string>>
     */
    private static function historyRows(OrderModel $record): array
    {
        return array_map(
            fn (OrderAdminEventView $event): array => [
                'occurred_at' => $event->occurredAt->format('Y-m-d H:i'),
                'type' => static::optionLabel('event_type', $event->type),
                'from_status' => static::optionLabel('status', $event->fromStatus),
                'to_status' => static::optionLabel('status', $event->toStatus),
                'reason' => $event->reason ?? __('orders.not_available'),
                'staff_name' => $event->staffName ?? __('orders.system_actor'),
            ],
            static::forOrder($record)->events,
        );
    }

    /** History's fixed column set, in order — see historyRows()'s own docblock for what each key is. @return array<int, array{key: string, label: string}> */
    private static function historyColumnSpecs(): array
    {
        return [
            ['key' => 'occurred_at', 'label' => __('orders.fields.occurred_at')],
            ['key' => 'type', 'label' => __('orders.fields.event_type')],
            ['key' => 'from_status', 'label' => __('orders.fields.from_status')],
            ['key' => 'to_status', 'label' => __('orders.fields.to_status')],
            ['key' => 'reason', 'label' => __('orders.fields.reason')],
            ['key' => 'staff_name', 'label' => __('orders.fields.staff_name')],
        ];
    }
}
