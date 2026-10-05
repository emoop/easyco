<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Rules\KnownCountryCode;
use App\Filament\Resources\OrderResource\RefundDialog;
use App\Services\Exceptions\MoneyOnlyRefundRefusedException;
use App\Services\Exceptions\OperationKeyReusedException;
use App\Services\Exceptions\OrderAddLineRefusedException;
use App\Services\Exceptions\OrderTransitionRefusedException;
use App\Services\Exceptions\PendingPaymentRefundRuleException;
use App\Services\Exceptions\PendingShippingInvariantBrokenException;
use App\Services\Exceptions\PromotionNoLongerValidException;
use App\Services\Exceptions\RefundCapExceededException;
use App\Services\Exceptions\RefundPermissionDeniedException;
use App\Services\Exceptions\RefundTransitionRefusedException;
use App\Services\Exceptions\ReturnAnnouncedDateException;
use App\Services\Exceptions\ReturnExceedsRemainingQuantityException;
use App\Services\Exceptions\StaleOrderEditException;
use App\Services\MoneyInput;
use App\Services\MoneyOnlyRefunder;
use App\Services\MoneyOnlyRefundRequest;
use App\Services\OrderAddLinePricer;
use App\Services\OrderAdminEventView;
use App\Services\OrderAdminOrderView;
use App\Services\OrderAdminReader;
use App\Services\OrderAdminSaleLineView;
use App\Services\OrderCurrentLinesResolver;
use App\Services\OrderContextReader;
use App\Services\OrderContextView;
use App\Services\OrderEditFormMapper;
use App\Services\OrderEditor;
use App\Services\OrderLineProductSearch;
use App\Services\OrderNoteRecorder;
use App\Services\OrderPaymentConfirmer;
use App\Services\OrderRefundsReader;
use App\Services\OrderStatusChanger;
use App\Services\PanelStaffActor;
use App\Services\PriceDisplayFormatter;
use App\Services\ProductPriceDisplay;
use App\Services\RefundFormContext;
use App\Services\RefundFormReader;
use App\Services\RefundPermissionPolicy;
use App\Services\RefundRequest;
use App\Services\RefundStatusChanger;
use App\Settings\CountryNames;
use App\Settings\StoreLocale;
use BackedEnum;
use Closure;
use DateTimeImmutable;
use EasyCo\Inventory\Exceptions\InsufficientStockException;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Exceptions\InvalidOrderTransitionException;
use EasyCo\Order\Exceptions\OrderNotEditableException;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Enums\RefundChannel;
use EasyCo\Payment\Payment;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\Exceptions\PriceNotConfiguredException;
use EasyCo\Pricing\Money;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn as RepeaterTableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\Entry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as RepeatableTableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
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
     * §14's line thumbnail: 36 px TALL — and HEIGHT ONLY, never a width —
     * the merchant's own size for it, named here rather than repeated as a
     * bare number in a cell and in a test.
     *
     * WHY NO WIDTH: Filament's ImageEntry writes BOTH dimensions into the
     * <img>'s inline style as soon as it is ->square() (or given
     * ->imageSize()), and a fixed height/width pair crops a photo that is
     * not square into its square box (the theme's own `object-cover`) —
     * that pairing is what made a line's photo look wrong. ->imageHeight()
     * ALONE renders `style="height: 36px;"` with no width at all, so each
     * photo keeps its real aspect ratio at a uniform 36px line height.
     */
    private const LINE_THUMBNAIL_HEIGHT_PX = 36;

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
                    ->formatStateUsing(fn (?string $state): string => static::optionLabel('status', $state))
                    ->badge()
                    ->color(fn (string $state): string => static::statusColor($state)),
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
     * TWO COLUMNS SINCE THE ORDER-VIEW LAYOUT (a main column of 2/3 and a sidebar of 1/3 from the `lg` breakpoint,
     * one column below it; History full width) — the paragraph below is the history of why the page was ONE column
     * before, when sections of very different heights were paired by the default grid. The new layout puts a
     * Group in each column, so a short card can never sit beside a tall one with a gap under it.
     *
     * ONE COLUMN, DELIBERATELY (until that layout) — the sections stack, they are never paired
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
        return $schema->components(static::compactType([
            // THE HEADER (order-view layout): the order number, its status and its payment status next to it, and under
            // them ONE muted line: payment method · channel · placement date · customer IP.
            Grid::make(['default' => 2, 'lg' => 4])
                ->schema([
                    TextEntry::make('id')
                        ->label(__('orders.fields.id'))
                        ->weight('bold'),
                    TextEntry::make('status')
                        ->label(__('orders.fields.status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => __("orders.status_options.{$state}"))
                        ->color(fn (string $state): string => static::statusColor($state)),
                    TextEntry::make('header_payment_status')
                        ->label(__('orders.fields.payment_status'))
                        ->badge()
                        ->visible(fn (OrderModel $record): bool => static::forOrder($record)->latestPayment !== null)
                        ->getStateUsing(fn (OrderModel $record): string => static::forOrder($record)->latestPayment !== null
                            ? static::optionLabel('payment_status', static::forOrder($record)->latestPayment->status()->value)
                            : __('orders.no_payment')),
                    TextEntry::make('header_subtitle')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->getStateUsing(fn (OrderModel $record): string => static::headerSubtitle($record)),
                ]),
            // TWO COLUMNS on a large screen — the main column (2/3) and the sidebar (1/3) — and ONE column below it, the main
            // column first. Filament's own Grid/Group only: the column counts and spans are inline CSS variables, so no
            // theme build is needed. History spans the full width underneath.
            Grid::make(['default' => 1, 'lg' => 3])
                ->schema([
                    Group::make([
                    Section::make(__('orders.sections.lines'))
                        ->compact()
                        ->schema([
                            // EACH LINE IS ONE BLOCK, not a table row: thumbnail, product name (a link to the product's edit page),
                            // muted details under it, the line total on the right. It wraps naturally on a phone, so it has no
                            // minimum width and no sideways scroll (History keeps its own).
                            RepeatableEntry::make('lines')
                                ->hiddenLabel()
                                ->getStateUsing(fn (OrderModel $record): array => static::lineRows($record))
                                ->schema(static::compactType([
                                    TextEntry::make('block')->hiddenLabel()->html(),
                                ])),
                            // THE MONEY SUMMARY under the items: subtotal, discount (code and redeemed suffix), shipping (method),
                            // TOTAL; then paid / refunded / owed when they are relevant. Right-aligned, two columns.
                            TextEntry::make('money_summary')
                                ->hiddenLabel()
                                ->html()
                                ->getStateUsing(fn (OrderModel $record): string => static::moneySummaryHtml($record)),
                        ])
                        // D5 (stage 4b-ii) — EDIT NOW LIVES HERE, IN THIS SECTION'S
                        // OWN HEADER, not in the page-wide action row above.
                        // Filament renders a Section's ->headerActions() at the
                        // RIGHT EDGE of its title bar with no alignment option
                        // needed, verified against the installed v5.8.1 source
                        // rather than assumed: Section::setUp() wires them into its
                        // `after_header` child schema
                        // (Schemas/Components/Section.php), Section::makeChildSchema()
                        // then calls $schema->alignEnd() on exactly that key, and the
                        // stylesheet's `.fi-section-header-text-ctn { @apply grid
                        // flex-1 }` (support/resources/css/components/section.css)
                        // is what pushes the action container to the far edge while
                        // `.fi-section-header-after-ctn { @apply self-center }`
                        // centres it vertically. The heading is what makes the row
                        // exist at all, so this section's own "Items" title is the
                        // left half of the pair.
                        //
                        // WHY THE MOVE AT ALL: an order's lines are edited from the
                        // lines, and the page-wide row above now holds exactly the
                        // actions that change the order's STATUS or record something
                        // about the order (see orderActions()'s own docblock). The
                        // action object itself is untouched — same ->visible()
                        // (ORDER_MANAGE, placed/confirmed, no settled payment), same
                        // dialog, same service call, same refusal mapping, same
                        // redirect. Only where Filament paints its trigger changed.
                        //
                        // A consequence worth naming, because it is visible to
                        // tests rather than to merchants: this action is no longer
                        // one of the page's cached HEADER actions, so mounting it by
                        // name alone no longer resolves — a Livewire round trip must
                        // carry the schema-component context Filament's own button
                        // sends (Action::getContext() → getSchemaComponent()'s key,
                        // resolved by InteractsWithActions::resolveSchemaComponentAction()).
                        // Every test drives it through that same context.
                        ->headerActions([static::editAction()]),
                    Section::make(__('orders.sections.payment'))
                        ->compact()
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
                            // §8.4's hole, closed (stage 7c-1): before these two
                            // entries, NOT ONE fact in this section changed when
                            // "Mark as received" ran — the section kept rendering
                            // "Pending" and nothing moved at all, which reads like a
                            // failed click. Both read the SAME forOrder() call every
                            // other closure here makes, off the same Payment object,
                            // so neither adds a query: the reader's own payment read
                            // already carries the columns §4/§7.3 added, because
                            // OrderAdminOrderView::latestPayment is the domain
                            // Payment itself, not a copy of a few of its fields
                            // (§8.4 puts it as "that DTO's latest-payment part gains
                            // confirmedAt and voidedAt" — this DTO's latest-payment
                            // part IS the aggregate that already exposes both, so the
                            // intent is met without a second home for the two dates).
                            //
                            // IS "SETTLED" THE PREDICATE R8/R9 CALL? LITERALLY:
                            // Payment::isSettled() is the one place §11 item 17 puts
                            // "money is held" (§4.1), so this badge cannot disagree
                            // with the guard that decides whether the order may ship
                            // or how much may be refunded. It stays a SEPARATE entry
                            // from payment_status on purpose: that one says exactly
                            // what the adapter said (§4.2's whole argument, including
                            // a raw value nothing here recognises), this one says
                            // whether money is held — a captured row is settled with
                            // no confirmation ever recorded, and only the pair can
                            // say both facts at once.
                            //
                            // A BADGE ONLY FOR THE POSITIVE FACT, AND NO COLOUR
                            // ANYWHERE. ->badge() takes a closure, so "settled" wears
                            // a badge and the not-recorded case is a plain sentence —
                            // §4.5 requires exactly that: the money stated as not
                            // recorded, "no computed 'unpaid' badge" (§3 item 3) and
                            // "no silence either". No ->color() for either state,
                            // matching payment_status' own bare badge above: a green
                            // "settled" beside a colourless "Captured" would rank two
                            // facts §4.2 deliberately keeps side by side, and the
                            // colour would say nothing the words do not.
                            //
                            // VISIBLE WHENEVER A PAYMENT ROW EXISTS — including the
                            // COD delivery that could not confirm anything (§4.5's
                            // first face), where it reads "Money not recorded" rather
                            // than vanishing. For an order with NO payment row at
                            // all, the two entries above already say it in this
                            // page's own established words (orders.no_payment on
                            // method and status), so this one has no subject to speak
                            // about and stays hidden instead of repeating it a third
                            // time. getStateUsing() still reads defensively, like the
                            // sibling above: Filament may evaluate state itself.
                            TextEntry::make('payment_settled')
                                ->label(__('orders.fields.payment_settled'))
                                ->badge(fn (OrderModel $record): bool => static::forOrder($record)->latestPayment?->isSettled() === true)
                                ->visible(fn (OrderModel $record): bool => static::forOrder($record)->latestPayment !== null)
                                ->getStateUsing(function (OrderModel $record): string {
                                    $payment = static::forOrder($record)->latestPayment;

                                    return $payment !== null && $payment->isSettled()
                                        ? __('orders.payment_settled_yes')
                                        : __('orders.payment_settled_no');
                                }),
                            // The instant §4.1's confirm() ran — NOT attemptedAt()
                            // (when the adapter answered) and not the payment's
                            // placement: the same honest, narrower claim Payment's
                            // own confirmedAt() docblock makes, in this section's own
                            // timestamp format. Hidden when NULL, the ordinary state
                            // of an online-captured or still-pending payment — the
                            // entry states a fact, it does not stand in for a missing
                            // one ('—' would claim a record exists).
                            //
                            // The confirmation ACTION needs nothing here: it is
                            // already hidden once the payment stops being confirmable
                            // (markAsReceivedAction()'s own ->visible(), which
                            // isConfirmable() makes false the moment confirmedAt is
                            // set), so §8.4's "the button's absence is the correct
                            // state, not a missing feature" holds today — this entry
                            // is what turns that absence from silence into a
                            // statement.
                            TextEntry::make('payment_confirmed_at')
                                ->label(__('orders.fields.payment_confirmed_at'))
                                ->visible(fn (OrderModel $record): bool => static::forOrder($record)->latestPayment?->confirmedAt() !== null)
                                ->getStateUsing(fn (OrderModel $record): ?string => static::forOrder($record)->latestPayment?->confirmedAt()?->format('Y-m-d H:i')),
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
                        ])
                        // D3 (tightened): columns(4), matching Summary's own
                        // density (id/placed_at/status/channel, one row of 4) —
                        // not one fact per full-width row.
                        ->columns(3),
                    // Every payment that is NOT the current one, collapsed by default so
                    // the everyday view stays quiet. It replaces the old "N attempts"
                    // count, which added a customer's genuine retries to the reissues an
                    // edit or a return causes and so said nothing true. Hidden outright
                    // when there is nothing to disclose (an order with one payment).
                    Section::make(__('orders.payment_history.heading'))
                        ->compact()
                        ->collapsible()
                        ->collapsed()
                        ->visible(fn (OrderModel $record): bool => static::paymentHistoryRows($record) !== [])
                        ->schema([
                            RepeatableEntry::make('payment_history')
                                ->hiddenLabel()
                                ->getStateUsing(fn (OrderModel $record): array => static::paymentHistoryRows($record))
                                ->table([
                                    RepeatableTableColumn::make(__('orders.payment_history.amount')),
                                    RepeatableTableColumn::make(__('orders.fields.payment_method')),
                                    RepeatableTableColumn::make(__('orders.fields.payment_status')),
                                    RepeatableTableColumn::make(__('orders.fields.attempted_at')),
                                    RepeatableTableColumn::make(__('orders.payment_history.why')),
                                ])
                                ->schema([
                                    TextEntry::make('amount')->hiddenLabel(),
                                    TextEntry::make('method')->hiddenLabel(),
                                    TextEntry::make('status')->hiddenLabel(),
                                    TextEntry::make('attempted_at')->hiddenLabel(),
                                    TextEntry::make('why')->hiddenLabel(),
                                ]),
                        ]),
                    // Refunds R2b (shipping-domain-design.md §7.2.5, §7.2.17): nothing at all (no empty box)
                    // for an order that has no refund; otherwise the four figures and one block per refund.
                    Section::make(fn (OrderModel $record): string => __('orders.refunds.heading_count', ['count' => count(static::refundsView($record)['rows'])]))
                        ->compact()
                        ->collapsible()
                        ->collapsed()
                        ->visible(fn (OrderModel $record): bool => static::refundsView($record)['rows'] !== [])
                        ->schema(fn (OrderModel $record): array => static::compactType(static::refundsSchema($record))),
                    ])->columnSpan(['lg' => 2]),
                    Group::make([
                    Section::make(__('orders.sections.client'))
                        ->compact()
                        ->schema([
                            TextEntry::make('client_name')
                                ->label(__('orders.fields.client_name'))
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['style' => self::PAIR_STYLE])
                                ->weight('medium')
                                ->getStateUsing(fn (OrderModel $record): string => static::forOrder($record)->clientName),
                            TextEntry::make('email')
                                ->label(__('orders.fields.email'))
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['style' => self::PAIR_STYLE])
                                ->copyable(),
                            TextEntry::make('phone')
                                ->label(__('orders.fields.phone'))
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['style' => self::PAIR_STYLE])
                                ->copyable(),
                            // Guest or account: the account id when there is one, "Guest" when there is not.
                            TextEntry::make('account_id')
                                ->label(__('orders.fields.account_id'))
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['style' => self::PAIR_STYLE])
                                ->getStateUsing(fn (OrderModel $record): string => filled($record->account_id) ? (string) $record->account_id : __('orders.guest')),
                            static::divider(),
                            TextEntry::make('client_id')
                                ->label(__('orders.fields.client_id'))
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['style' => self::PAIR_STYLE])
                                ->getStateUsing(fn (OrderModel $record): string => static::forOrder($record)->order->clientId()),
                        ])
                        ->columns(1),
                    Section::make(__('orders.sections.delivery'))
                        ->compact()
                        ->schema([
                            TextEntry::make('delivery_type')
                                ->label(__('orders.fields.delivery_type'))
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['style' => self::PAIR_STYLE])
                                ->formatStateUsing(fn (string $state): string => __("orders.delivery_type_options.{$state}")),
                            static::divider(),
                            // The address (a street address) or the pickup point: every part the order stores, in one block.
                            TextEntry::make('delivery_address')
                                ->hiddenLabel()
                                ->html()
                                ->getStateUsing(fn (OrderModel $record): string => static::deliveryAddressHtml($record)),
                        ])
                        ->columns(1),
                    Section::make(__('orders.sections.payment_shipping'))
                        ->compact()
                        ->schema([
                            TextEntry::make('side_payment_method')
                                ->label(__('orders.fields.payment_method'))
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['style' => self::PAIR_STYLE])
                                ->getStateUsing(fn (OrderModel $record): string => static::forOrder($record)->latestPayment !== null
                                    ? static::optionLabel('payment_method', static::forOrder($record)->latestPayment->method())
                                    : __('orders.no_payment')),
                            // The shipping method the customer chose (the snapshot taken at placement) with its price.
                            TextEntry::make('shipping_method_name')
                                ->label(__('orders.fields.shipping_method'))
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['style' => self::PAIR_STYLE])
                                ->visible(fn (OrderModel $record): bool => filled($record->shipping_method_name))
                                ->getStateUsing(fn (OrderModel $record): string => $record->shipping_method_name.' · '.static::formatOrderMoney($record, 'shipping_minor')),
                            TextEntry::make('tracking_number')
                                ->label(__('orders.fields.tracking_number'))
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['style' => self::PAIR_STYLE])
                                ->visible(fn (OrderModel $record): bool => filled($record->tracking_number))
                                ->copyable(),
                        ])
                        ->columns(1),
                    // The order-context fields: designed, not built. Read ONLY through OrderContextReader; a null is "n/a".
                    Section::make(__('orders.sections.order_details'))
                        ->compact()
                        ->schema([
                            TextEntry::make('ctx_terms_accepted')
                                ->label(__('orders.context.fields.terms_accepted'))
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['style' => self::PAIR_STYLE])
                                ->getStateUsing(fn (OrderModel $record): string => static::contextText(static::context($record)->termsAccepted)),
                            TextEntry::make('ctx_confirmation_requested')
                                ->label(__('orders.context.fields.confirmation_requested'))
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['style' => self::PAIR_STYLE])
                                ->getStateUsing(fn (OrderModel $record): string => static::contextText(static::context($record)->confirmationRequested)),
                            TextEntry::make('ctx_call_before_shipping')
                                ->label(__('orders.context.fields.call_before_shipping'))
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['style' => self::PAIR_STYLE])
                                ->getStateUsing(fn (OrderModel $record): string => static::contextText(static::context($record)->callBeforeShipping)),
                        ])
                        ->columns(1),
                    // Later it appears only when an invoice was requested; for now there is nothing recorded, so it shows n/a.
                    Section::make(__('orders.sections.invoice'))
                        ->compact()
                        ->schema([
                            TextEntry::make('ctx_company_name')
                                ->label(__('orders.context.fields.company_name'))
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['style' => self::PAIR_STYLE])
                                ->getStateUsing(fn (OrderModel $record): string => static::contextText(static::context($record)->invoiceCompanyName)),
                            TextEntry::make('ctx_vat_number')
                                ->label(__('orders.context.fields.vat_number'))
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['style' => self::PAIR_STYLE])
                                ->getStateUsing(fn (OrderModel $record): string => static::contextText(static::context($record)->invoiceVatNumber)),
                            TextEntry::make('ctx_billing_address')
                                ->label(__('orders.context.fields.billing_address'))
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['style' => self::PAIR_STYLE])
                                ->getStateUsing(fn (OrderModel $record): string => static::contextText(static::context($record)->invoiceBillingAddress)),
                        ])
                        ->columns(1),
                    Section::make(__('orders.sections.origin'))
                        ->compact()
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            // The visitor's anonymous id (a UUIDv4, order-context-design.md §2.1): its first 8 characters and an
                            // ellipsis; the FULL value is the tooltip and what the copy button copies. Not recorded: "n/a", no tooltip.
                            TextEntry::make('ctx_visitor_id')
                                ->label(__('orders.context.fields.visitor_id'))
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['style' => self::PAIR_STYLE])
                                ->getStateUsing(fn (OrderModel $record): string => static::contextText(static::context($record)->visitorId))
                                ->formatStateUsing(fn (OrderModel $record): string => static::shortId(static::context($record)->visitorId))
                                ->tooltip(fn (OrderModel $record): ?string => static::context($record)->visitorId)
                                ->copyable(fn (OrderModel $record): bool => static::context($record)->visitorId !== null)
                                ->copyableState(fn (OrderModel $record): string => (string) static::context($record)->visitorId),
                            TextEntry::make('ctx_source_type')
                                ->label(__('orders.context.fields.source_type'))
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['style' => self::PAIR_STYLE])
                                ->getStateUsing(fn (OrderModel $record): string => static::contextText(static::context($record)->originSourceType)),
                            TextEntry::make('ctx_campaign')
                                ->label(__('orders.context.fields.campaign'))
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['style' => self::PAIR_STYLE])
                                ->getStateUsing(fn (OrderModel $record): string => static::contextText(static::context($record)->originCampaign)),
                            TextEntry::make('ctx_landing_page')
                                ->label(__('orders.context.fields.landing_page'))
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['style' => self::PAIR_STYLE])
                                ->getStateUsing(fn (OrderModel $record): string => static::contextText(static::context($record)->originLandingPage)),
                            TextEntry::make('ctx_referrer')
                                ->label(__('orders.context.fields.referrer'))
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['style' => self::PAIR_STYLE])
                                ->getStateUsing(fn (OrderModel $record): string => static::contextText(static::context($record)->originReferrer)),
                        ])
                        ->columns(1),
                    ])->columnSpan(['lg' => 1]),
                ]),
            Section::make(__('orders.sections.history'))
                ->compact()
                ->columnSpanFull()
                ->schema([
                    static::scrollable(RepeatableEntry::make('history')
                        ->hiddenLabel()
                        ->getStateUsing(fn (OrderModel $record): array => static::historyRows($record))
                        ->table(array_map(
                            static fn (array $spec): RepeatableTableColumn => RepeatableTableColumn::make($spec['label']),
                            static::historyColumnSpecs(),
                        ))
                        ->schema(array_map(
                            static fn (array $spec): Entry => $spec['key'] === 'return_record'
                                ? TextEntry::make($spec['key'])->hiddenLabel()->listWithLineBreaks()
                                : TextEntry::make($spec['key'])->hiddenLabel(),
                            static::historyColumnSpecs(),
                        )), 64),
                ]),
        ]))->columns(1);
    }

    /**
     * THE ONE HELPER of the page's compact type (order-view layout): walks the components the infolist builds and
     * sets TextSize::ExtraSmall on every TextEntry (a TextEntry's DEFAULT is already Small, so Small would change nothing;
     * `fi-size-xs` exists in the precompiled panel CSS). LOCAL to this schema — no static or global configuration, so no other
     * page, and no later request in a long-lived worker, is ever affected.
     *
     * It walks the components whose children are already built. A component whose schema is a Closure (the items
     * table, the refunds section) cannot be walked here — its closure needs the record — so each of those passes
     * what it builds through this same helper itself, where it builds it.
     *
     * @param  array<int, mixed>  $components
     * @return array<int, mixed> the same components
     */
    private static function compactType(array $components): array
    {
        foreach ($components as $component) {
            if ($component instanceof TextEntry) {
                $component->size(TextSize::ExtraSmall);

                if (static::isSecondaryEntry($component->getName())) {
                    $component->color('gray')->extraAttributes(['style' => self::SECONDARY_ENTRY_STYLE], merge: true);
                }

                continue;
            }

            if (is_object($component)) {
                static::compactType(static::builtChildren($component));
            }
        }

        return $components;
    }

    /** @return array<int, mixed> a component's default children when they are an array (not a Closure), else none */
    private static function builtChildren(object $component): array
    {
        try {
            $children = (new \ReflectionProperty($component, 'childComponents'))->getValue($component);
        } catch (\ReflectionException) {
            return [];
        }

        return is_array($children['default'] ?? null) ? $children['default'] : [];
    }

    /** The muted style of the page's SECONDARY level of text (labels' companions: dates, references, SKU, unit prices). */
    private const SECONDARY_ENTRY_STYLE = 'font-size: 0.6875rem';

    /**
     * A "label: value" pair of a sidebar card is a WRAPPING flex row (not Filament's fixed 1/3 + 2/3 grid): a long label can
     * never run into its value — when the two do not fit side by side the value drops BELOW the label, and long words break.
     */
    private const PAIR_STYLE = 'display: flex; flex-wrap: wrap; column-gap: 0.75rem; row-gap: 0.125rem; overflow-wrap: anywhere';

    private const SECONDARY_HTML_STYLE = 'font-size: 0.6875rem; opacity: 0.65';

    /**
     * ONE place decides which text is SECONDARY on this page (smaller and muted) and which is primary (names, amounts,
     * totals, the customer's name — left as they are): the entries named here. compactType() applies it.
     */
    private static function isSecondaryEntry(string $name): bool
    {
        return in_array($name, [
            'payment_confirmed_at', 'attempted_at', 'provider_reference', 'failure_reason', 'client_id', 'header_subtitle',
            'occurred_at', 'staff_name', 'return_record',
        ], true) || preg_match('/^refund_\d+_(recorded_at|recorded_by|reason|paid_out_at|paid_out_by|paid_out_reference|paid_out_note|cancelled_at|cancelled_by|cancelled_reason|deduction_reason)$/', $name) === 1;
    }

    /** A thin divider between groups of a card, theme-safe in light and dark mode (a translucent line, no colour). */
    private static function divider(): Html
    {
        return Html::make('<hr style="border: 0; border-top: 1px solid rgba(128, 128, 128, 0.3); margin: 0.25rem 0">');
    }

    private static function context(OrderModel $record): OrderContextView
    {
        return app(OrderContextReader::class)->forOrder((string) $record->id);
    }

    /** The first 8 characters of an id and an ellipsis (a UUID's first group); not recorded is "n/a". */
    private static function shortId(?string $id): string
    {
        return $id === null ? __('orders.context.not_recorded') : mb_substr($id, 0, 8).'…';
    }

    /** A recorded value as text; null is "n/a" (not recorded) — never "No". */
    private static function contextText(string|bool|null $value): string
    {
        return match (true) {
            $value === null => __('orders.context.not_recorded'),
            is_bool($value) => $value ? __('orders.yes') : __('orders.no'),
            default => $value,
        };
    }

    /** payment method · channel · placement date · customer IP — the muted line under the order number. */
    private static function headerSubtitle(OrderModel $record): string
    {
        $view = static::forOrder($record);
        $placed = $record->placed_at instanceof \DateTimeInterface ? Carbon::instance($record->placed_at) : Carbon::parse((string) $record->placed_at);

        return implode(' · ', array_filter([
            $view->latestPayment !== null ? static::optionLabel('payment_method', $view->latestPayment->method()) : null,
            static::optionLabel('channel', $view->channel),
            $placed->timezone(FilamentTimezone::get())->format('M j, Y H:i'),
            __('orders.context.fields.ip_short').' '.static::contextText(static::context($record)->customerIp),
        ]));
    }

    /** The street address, or the pickup point, with every part the order stores. */
    private static function deliveryAddressHtml(OrderModel $record): string
    {
        $na = __('orders.not_available');
        $lines = $record->delivery_type === OrderDeliveryType::STREET_ADDRESS->value
            ? [
                $record->address_line_1 ?? $na,
                $record->address_line_2,
                trim(($record->postal_code ?? '').' '.($record->city ?? $na)),
                $record->country ?? $na,
            ]
            : [
                __('orders.fields.carrier_code').': '.($record->carrier_code ?? $na),
                __('orders.fields.pickup_point_reference').': '.($record->pickup_point_reference ?? $na),
                $record->settlement ?? $na,
                $record->country ?? $na,
            ];

        return implode('<br>', array_map(static fn (string $line): string => e($line), array_filter($lines, static fn (?string $line): bool => $line !== null && $line !== '')));
    }

    /** One line as a BLOCK: thumbnail, name (a link to the product's edit page), muted details, the total on the right. */
    private static function lineBlockHtml(OrderAdminSaleLineView $line): string
    {
        $muted = 'style="'.self::SECONDARY_HTML_STYLE.'"';
        $name = e($line->productName ?? __('orders.not_available'));

        $title = $line->productId !== null
            ? '<a class="fi-link" href="'.e(ProductResource::getUrl('edit', ['record' => $line->productId])).'" target="_blank" rel="noopener noreferrer" style="font-weight: 600; word-break: break-word">'.$name.'</a>'
            : '<span style="font-weight: 600; word-break: break-word">'.$name.'</span>';

        $details = [];

        // "€50.00 × 1": the sold unit price (its struck regular price kept) and the quantity.
        $details[] = static::lineUnitPriceHtml($line).' × '.e((string) $line->quantity);

        foreach ($line->soldAttributes as $attribute) {
            $details[] = e(($attribute['definitionName'] ?? '').': '.($attribute['value'] ?? ''));
        }

        if ($line->sku !== null) {
            $details[] = e(__('orders.line_labels.sku').': '.$line->sku);
        }

        if (! $line->isLegacy && $line->promotionDiscountShare !== null && $line->promotionDiscountShare->minorValue() !== 0) {
            $details[] = e(__('orders.line_labels.discount').': -'.static::formatLineMoney($line->promotionDiscountShare));
        }

        if ($line->discretionaryDiscount !== null && $line->discretionaryDiscount->minorValue() !== 0) {
            $details[] = e(__('orders.fields.discretionary_discount').': -'.static::formatLineMoney($line->discretionaryDiscount));
        }

        // Returned or removed units stay visible: what is no longer returnable or editable of the ordered quantity.
        $gone = $line->quantity - $line->remainingReturnable;

        if ($gone > 0) {
            $details[] = e(__('orders.line_labels.returned_or_removed', ['count' => $gone]));
        }

        if ($line->isLegacy) {
            $details[] = e(__('orders.legacy_line_note'));
        }

        // HEIGHT ONLY, never a width (LINE_THUMBNAIL_HEIGHT_PX's docblock has the why); and fail-soft like the ImageEntry it
        // replaces: a file that is not there renders no image, never a broken one.
        $disk = \Illuminate\Support\Facades\Storage::disk(config('services.media.default_disk', 'public'));
        $image = $line->imagePath !== null && $disk->exists($line->imagePath)
            ? '<img src="'.e($disk->url($line->imagePath)).'" alt="" style="height: '.self::LINE_THUMBNAIL_HEIGHT_PX.'px; flex: none; border-radius: 0.375rem">'
            : '';

        return '<div style="display: flex; flex-wrap: wrap; gap: 0.5rem 0.75rem; align-items: flex-start; width: 100%">'
            .$image
            .'<div style="flex: 1 1 12rem; min-width: 0">'.$title
            .implode('', array_map(static fn (string $detail): string => '<div '.$muted.'>'.$detail.'</div>', $details))
            .'</div>'
            .'<div style="margin-inline-start: auto; font-weight: 700; white-space: nowrap">'.e(static::formatLineMoney($line->isLegacy ? null : $line->netPaidAmount)).'</div>'
            .'</div>';
    }

    /**
     * The money summary under the items: a compact, right-aligned two-column block. Subtotal, discount (with the code and
     * whether it is redeemed), shipping (with the method), TOTAL in bold; then — when there is a settled payment or any
     * refund — paid, refunded (paid out) and refund owed (only when not zero). The refund figures are R2b's
     * (OrderRefundsReader): nothing is computed here.
     */
    private static function moneySummaryHtml(OrderModel $record): string
    {
        $money = static fn (string $field): string => static::formatOrderMoney($record, $field);
        $row = static fn (string $label, string $value, bool $bold = false): string => '<tr'.($bold ? ' style="font-weight: 700"' : '').'>'
            .'<td style="padding: 0.125rem 1rem 0.125rem 0; text-align: end">'.$label.'</td>'
            .'<td style="padding: 0.125rem 0; text-align: end; white-space: nowrap">'.e($value).'</td></tr>';

        $discountLabel = e(__('orders.fields.discount'));

        if ($record->applied_promotion_code !== null) {
            $discountLabel .= ' ('.e($record->applied_promotion_code).')'
                .' <span style="'.self::SECONDARY_HTML_STYLE.'">· '.e(static::forOrder($record)->hasPromotionRedemption ? __('orders.promotion_redeemed_yes') : __('orders.promotion_redeemed_no')).'</span>';
        }

        $shippingLabel = e(__('orders.fields.shipping')).(filled($record->shipping_method_name) ? ' ('.e($record->shipping_method_name).')' : '');

        $html = $row(e(__('orders.fields.subtotal')), $money('subtotal_minor'))
            .$row($discountLabel, '-'.$money('discount_minor'))
            .$row($shippingLabel, $money('shipping_minor'))
            .$row(e(__('orders.fields.total')), $money('total_minor'), true);

        $refunds = static::refundsView($record);
        $payment = static::forOrder($record)->latestPayment;
        $settled = $payment !== null && $payment->isSettled();

        if ($settled || $refunds['rows'] !== []) {
            $paid = $refunds['rows'] !== [] ? $refunds['figures']['paid_in'] : $payment->amount()->minorValue();
            $format = static fn (int $minor): string => static::formatMinor($minor, $refunds['currency']);

            $html .= '<tr><td colspan="2" style="border-top: 1px solid rgba(128, 128, 128, 0.3); padding: 0.125rem 0"></td></tr>'
                .$row(e(__('orders.money.paid')), $format($paid))
                .$row(e(__('orders.money.refunded')), $format($refunds['figures']['paid_out']));

            if ($refunds['figures']['owed'] !== 0) {
                $html .= $row(e(__('orders.money.refund_owed')), $format($refunds['figures']['owed']));
            }
        }

        return '<table style="margin-inline-start: auto; border-collapse: collapse">'.$html.'</table>';
    }

    /**
     * A table that SCROLLS SIDEWAYS instead of squeezing its columns: the table sits in a container with
     * `overflow-x: auto` and has a minimum width, so below that width the container scrolls and every column keeps
     * its natural width. Inline styles only (no theme build); they carry no colours, so light and dark mode alike.
     */
    private static function scrollable(Entry $table, int $minWidthRem): Group
    {
        return Group::make([$table->extraAttributes(['style' => "min-width: {$minWidthRem}rem"])])
            ->extraAttributes(['style' => 'overflow-x: auto', 'class' => 'fi-order-scroll']);
    }

    /**
     * D1 (order-lifecycle-design.md §8.1, §10 stage 7c-2) — the STATUS /
     * record-keeping actions the View page's own header row offers, in
     * order: confirm, ship, deliver, mark_as_received, cancel,
     * record_return, add_note. `ViewOrder::getHeaderActions()` becomes
     * exactly `return OrderResource::orderActions();` — §8.1's own words —
     * so the Resource stays the one place that knows which actions exist
     * and the page stays a two-line adapter.
     *
     * EDIT IS NOT ONE OF THEM ANY MORE (stage 4b-ii, D5): editAction() is
     * now a HEADER ACTION OF THE "Items" SECTION itself — see that
     * section's own `->headerActions([static::editAction()])` in
     * infolist() below, and editAction()'s own docblock for why. This row
     * still owns every action that changes the ORDER'S STATUS or records
     * something about it; editing its lines is a fact about the lines, so
     * its trigger sits with them. Nothing else about the action moved: its
     * own ->visible(), ->schema() and ->action() are byte-for-byte what
     * they were, and no other action on this page changed place.
     */
    public static function orderActions(): array
    {
        return [
            static::confirmAction(),
            static::shipAction(),
            static::deliverAction(),
            static::markAsReceivedAction(),
            static::cancelAction(),
            static::recordReturnAction(),
            static::refundMoneyOnlyAction(),
            static::addNoteAction(),
        ];
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
     *
     * §10 STAGE 7C-2's OWN REFACTOR (D1): confirm()/ship()/deliver()'s own
     * ->visible() now reads `OrderStatus::from($record->status)->
     * canTransitionTo(OrderStatus::X)` instead of a direct `$record->status
     * === X->value` equality check — behaviourally IDENTICAL today (§8.2's
     * own reasoning: this matrix gives every target exactly one legal
     * predecessor, so "may this order become CONFIRMED" and "is this order
     * PLACED" already agree for every real row), but now covered by the
     * SAME `OrderStatus` transition-matrix drift test §10 stage 1 already
     * has, rather than a second, hand-kept list the panel and the domain
     * could silently disagree about.
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
                && OrderStatus::from($record->status)->canTransitionTo(OrderStatus::CONFIRMED))
            ->action(function (array $data, OrderModel $record, $livewire): void {
                static::runOrderAction(
                    $record,
                    $livewire,
                    fn () => app(OrderStatusChanger::class)->confirm((string) $record->id, new DateTimeImmutable, $data['note'] ?? null),
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
                && OrderStatus::from($record->status)->canTransitionTo(OrderStatus::SHIPPED))
            ->action(function (array $data, OrderModel $record, $livewire): void {
                static::runOrderAction(
                    $record,
                    $livewire,
                    fn () => app(OrderStatusChanger::class)->ship((string) $record->id, new DateTimeImmutable, $data['note'] ?? null),
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
                && OrderStatus::from($record->status)->canTransitionTo(OrderStatus::DELIVERED))
            ->action(function (array $data, OrderModel $record, $livewire): void {
                static::runOrderAction(
                    $record,
                    $livewire,
                    fn () => app(OrderStatusChanger::class)->deliver((string) $record->id, new DateTimeImmutable, $data['note'] ?? null),
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
                    fn () => app(OrderPaymentConfirmer::class)->confirm($payment->id(), new DateTimeImmutable),
                    __('orders.actions.mark_as_received_done'),
                );
            });
    }

    /**
     * D2 (order-lifecycle-design.md §8.2, §10 stage 7c-2). Visible when
     * `canTransitionTo(CANCELLED)` — placed/confirmed/shipped, per the
     * matrix — AND D3's money-permission clause. §8.2's own "two things
     * deliberately still offered when they might refuse" covers this
     * button too: a cancel from `shipped` whose share might exceed what
     * the payment can still refund is still shown; OrderRefunder's own
     * anomaly guard is what actually refuses it, translated by
     * runOrderAction()'s existing InvalidArgumentException branch.
     *
     * THE FORM (D4/§8.4): the SAME line blocks recordReturnAction() uses
     * (buildLineFormSchema()), with the quantity always a READ-ONLY
     * display (never collected — cancel() takes no quantities at all,
     * only a restock choice) and the restock Toggle offered ONLY when the
     * locked status is `shipped` — from placed/confirmed the toggle is
     * absent entirely (§8.4: "from placed/confirmed the toggles are not
     * offered at all, because the goods never left"), matching
     * `OrderStatusChanger::cancel()`'s own documented behaviour of
     * ignoring $restockOverrides entirely in that case.
     *
     * REPORTED DIFFERENCE FROM D4'S OWN LITERAL TEXT, RESOLVED IN THE
     * DESIGN DOC'S FAVOUR PER §0's OWN INSTRUCTION: D4 as written says
     * cancel() from placed/confirmed shows "NO line form at all". §8.4
     * itself says the opposite — "cancel opens the SAME FORM with the
     * quantities fixed at the remaining units and only the toggles
     * editable when the order is shipped; from placed/confirmed the
     * toggles are not offered at all" — i.e. the line blocks (read-only
     * quantities) DO render from placed/confirmed too, only the toggle is
     * missing. Implemented per §8.4: the merchant always sees what is
     * about to be cancelled, never a bare "are you sure" with nothing to
     * look at.
     */
    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('orders.actions.cancel'))
            ->color('danger')
            ->icon('heroicon-o-x-circle')
            ->requiresConfirmation()
            ->modalHeading(fn (OrderModel $record): string => __('orders.actions.cancel_heading', ['id' => $record->id]))
            ->modalDescription(fn (OrderModel $record): string => __('orders.actions.cancel_description', ['id' => $record->id]))
            ->schema(fn (OrderModel $record): array => static::buildLineFormSchema(
                $record,
                editableQuantity: false,
                showRestockToggle: $record->status === OrderStatus::SHIPPED->value,
            ))
            // A settled payment is refunded through a payout channel the staff member must hold the permission
            // of — but the dialog is offered regardless, so that staff without either channel are TOLD why
            // (a notice, and no submit button) instead of finding the button missing (refunds R3 part 2).
            ->modalSubmitAction(fn (OrderModel $record): ?bool => static::hasNoRefundChannel($record) ? false : null)
            ->visible(fn (OrderModel $record): bool => static::staffHasPermission(Permission::ORDER_MANAGE)
                && OrderStatus::from($record->status)->canTransitionTo(OrderStatus::CANCELLED))
            ->action(function (array $data, OrderModel $record, $livewire): void {
                $restockOverrides = $record->status === OrderStatus::SHIPPED->value
                    ? static::restockOverridesFromData($data, static::forOrder($record))
                    : [];

                static::runOrderAction(
                    $record,
                    $livewire,
                    fn () => app(OrderStatusChanger::class)->cancel((string) $record->id, new DateTimeImmutable, $data['reason'] ?? null, $restockOverrides, static::refundRequestFrom($data, $record, RefundDialog::CANCEL)),
                    __('orders.actions.cancel_done', ['id' => $record->id]),
                );
            });
    }

    /**
     * D2 (order-lifecycle-design.md §8.2, §10 stage 7c-2). Visible at
     * shipped/delivered — DELIBERATELY NOT a canTransitionTo() check: a
     * return is not itself a matrix target (§2.2 R2 — cancel/recordReturn
     * share one goods-return implementation, but only cancel's OWN
     * terminal move is a real transition; a partial return moves no
     * status at all, §2.3), so this is the one place D1's own
     * "read the matrix" rule does not apply, stated here rather than left
     * to read as an inconsistency.
     *
     * THE FORM: the SAME line blocks, quantity a real EDITABLE integer
     * input (`minValue(0)`, `maxValue($line->remainingReturnable)`,
     * blank by default — sparse, §8.4/§5.2) and the restock Toggle always
     * offered (a return is legal only from shipped/delivered, where the
     * goods already left — R3's flag is always a real per-line choice
     * here, never ignored).
     */
    public static function recordReturnAction(): Action
    {
        return Action::make('record_return')
            ->label(__('orders.actions.record_return'))
            ->color('warning')
            ->icon('heroicon-o-arrow-uturn-left')
            ->requiresConfirmation()
            ->modalHeading(fn (OrderModel $record): string => __('orders.actions.record_return_heading', ['id' => $record->id]))
            ->modalDescription(fn (OrderModel $record): string => __('orders.actions.record_return_description', ['id' => $record->id]))
            ->schema(fn (OrderModel $record): array => static::buildLineFormSchema(
                $record,
                editableQuantity: true,
                showRestockToggle: true,
            ))
            ->modalSubmitAction(fn (OrderModel $record): ?bool => static::hasNoRefundChannel($record) ? false : null)
            ->visible(fn (OrderModel $record): bool => static::staffHasPermission(Permission::ORDER_MANAGE)
                && in_array($record->status, [OrderStatus::SHIPPED->value, OrderStatus::DELIVERED->value], true))
            ->action(function (array $data, OrderModel $record, $livewire): void {
                $view = static::forOrder($record);
                $lines = [];
                $totalQuantity = 0;

                foreach ($view->lines as $line) {
                    if ($line->remainingReturnable <= 0) {
                        continue;
                    }

                    $quantity = (int) ($data['quantity'][$line->id] ?? 0);

                    if ($quantity <= 0) {
                        continue;
                    }

                    $lines[] = [
                        'originatingSaleLineId' => $line->id,
                        'quantityReturned' => $quantity,
                        'restock' => (bool) ($data['restock'][$line->id] ?? true),
                    ];
                    $totalQuantity += $quantity;
                }

                // D5's own client-side rule: nothing to submit is a form
                // error, not a service call — refused BEFORE
                // OrderStatusChanger ever runs, exactly like every guard
                // elsewhere on this page that checks before it writes.
                if ($lines === []) {
                    Notification::make()
                        ->title(__('orders.actions.refused_title'))
                        ->body(__('orders.actions.nothing_to_return'))
                        ->danger()
                        ->send();

                    return;
                }

                static::runOrderAction(
                    $record,
                    $livewire,
                    fn () => app(OrderStatusChanger::class)->recordReturn((string) $record->id, $lines, new DateTimeImmutable, $data['reason'] ?? null, static::refundRequestFrom($data, $record, RefundDialog::RETURN)),
                    __('orders.actions.record_return_done', ['id' => $record->id, 'count' => $totalQuantity]),
                );
            });
    }

    /**
     * "Refund money only" (refunds R3 part 2, shipping-domain-design.md §7.2.11): a refund of money with no goods
     * coming back — goodwill or a correction: a shipping refund and/or an adjustment, a mandatory reason, a payout
     * channel. Visible only with a SETTLED payment (nothing was paid otherwise) and at least one channel
     * permission; NO order status is required, so it is there on a delivered and on a cancelled order too. The
     * dialog sends one operation key generated when it opens; MoneyOnlyRefunder::record() is the service, and
     * every refusal it makes is a notice (runOrderAction).
     */
    public static function refundMoneyOnlyAction(): Action
    {
        return Action::make('refund_money_only')
            ->label(__('orders.money_only.label'))
            ->color('warning')
            ->icon('heroicon-o-banknotes')
            ->modalHeading(fn (OrderModel $record): string => __('orders.money_only.heading', ['id' => $record->id]))
            ->modalDescription(__('orders.money_only.description'))
            ->schema(fn (OrderModel $record): array => RefundDialog::moneyOnlySchema(app(RefundFormReader::class)->forOrder((string) $record->id)))
            ->visible(fn (OrderModel $record): bool => static::staffHasPermission(Permission::ORDER_MANAGE)
                && (static::forOrder($record)->latestPayment?->isSettled() ?? false)
                && ! static::hasNoRefundChannel($record, requireSettled: false))
            ->action(function (array $data, OrderModel $record, $livewire): void {
                $context = app(RefundFormReader::class)->forOrder((string) $record->id);
                $shipping = MoneyInput::parseOrZero(is_scalar($data['shipping'] ?? null) ? (string) $data['shipping'] : '', $context->currency);
                $adjustment = MoneyInput::parseOrZero(is_scalar($data['adjustment'] ?? null) ? (string) $data['adjustment'] : '', $context->currency);
                $channel = filled($data['channel'] ?? null) ? RefundChannel::from((string) $data['channel']) : $context->defaultChannel;

                if ($shipping === null || $adjustment === null || $channel === null) {
                    Notification::make()
                        ->title(__('orders.actions.refused_title'))
                        ->body($channel === null ? __('orders.refund_dialog.no_channel') : __('orders.refund_dialog.invalid_amount'))
                        ->danger()
                        ->send();

                    return;
                }

                $key = $data['operation_key'] ?? null;
                $request = new MoneyOnlyRefundRequest(
                    shipping: $shipping,
                    adjustment: $adjustment,
                    reason: (string) ($data['reason'] ?? ''),
                    channel: $channel,
                    operationKey: is_string($key) && $key !== '' ? $key : null,
                );

                static::runOrderAction(
                    $record,
                    $livewire,
                    fn () => app(MoneyOnlyRefunder::class)->record((string) $record->id, $request, new DateTimeImmutable),
                    __('orders.money_only.done', ['amount' => static::formatMinor($shipping->add($adjustment)->minorValue(), $context->currency)]),
                );
            });
    }

    /**
     * order-editing-design.md §8 (D7), stage 4b-i — the edit dialog: the
     * order's CURRENT lines (reduce or remove, optionally a manual discount),
     * its delivery, its promotion code, and an optional reason, submitted as
     * ONE App\Services\OrderEditor::apply() call.
     *
     * VISIBLE only for ORDER_MANAGE, at placed/confirmed, with no SETTLED
     * payment on the order: E3 makes an order with money captured simply not
     * editable, so the action is not offered (the service still refuses
     * truthfully if a stale page reaches it). Unlike cancel/return this is not
     * a money-permission conjunction: editing never moves captured money.
     *
     * THE LINE TABLE IS A Filament Repeater IN ITS OWN TABLE MODE
     * (Repeater::table([...TableColumn]) — confirmed in the installed v5.8.1
     * source), which renders the same header-row-over-cells look the read-only
     * Lines section above gets from RepeatableEntry::table(). It is fixed —
     * ->addable(false)->deletable(false)->reorderable(false) — because the row
     * set is exactly the order's current lines, known at render time. The price
     * of using a Repeater (its item state is keyed by an auto-generated key, not
     * by the line id, which is why cancel/return use a Fieldset per line
     * instead) is paid deliberately: one hidden `line_id` per row, which
     * OrderEditFormMapper reads; the item key is never used. A quantity can only
     * go DOWN here (maxValue = the line's current quantity; the mapper enforces
     * it again), 0 meaning remove; raising a quantity or adding a product is
     * another dialog's job. The discount cell exists only for ORDER_DISCOUNT —
     * absent, not merely refused, for anyone without it — and the mapper never
     * reads a discount without it either.
     *
     * THE CONCURRENCY TOKEN is the order's edit_revision as read when the
     * dialog OPENED, carried in a hidden field (the schema closure runs at
     * mount, on a freshly hydrated record), and handed to apply() untouched: if
     * anything edited the order in between, apply() refuses it as stale before
     * writing a thing.
     *
     * A SUBMISSION THAT CHANGES NOTHING IS REFUSED HERE, never sent: apply()
     * would faithfully bump the revision and write an `edited` event for an
     * edit that edited nothing, which is noise in an order's own history.
     */
    public static function editAction(): Action
    {
        return Action::make('edit_order')
            ->label(__('orders.actions.edit'))
            ->color('primary')
            ->icon('heroicon-o-pencil')
            ->modalHeading(fn (OrderModel $record): string => __('orders.actions.edit_heading', ['id' => $record->id]))
            ->modalDescription(fn (OrderModel $record): string => __('orders.actions.edit_description', ['id' => $record->id]))
            ->modalWidth('5xl')
            ->schema(fn (OrderModel $record): array => static::buildEditFormSchema($record))
            ->visible(fn (OrderModel $record): bool => static::staffHasPermission(Permission::ORDER_MANAGE)
                && in_array($record->status, [OrderStatus::PLACED->value, OrderStatus::CONFIRMED->value], true)
                && ! (static::forOrder($record)->latestPayment?->isSettled() ?? false))
            ->action(function (array $data, OrderModel $record, $livewire): void {
                try {
                    $order = app(OrderRepository::class)->findById((string) $record->id);

                    if ($order === null) {
                        throw new InvalidArgumentException('order vanished');
                    }

                    $currentLinesById = [];
                    foreach (app(OrderCurrentLinesResolver::class)->resolve($order) as $line) {
                        $currentLinesById[(string) $line->id()] = $line;
                    }

                    $lineChanges = OrderEditFormMapper::lineChanges(
                        array_values($data['lines'] ?? []),
                        $currentLinesById,
                        static::staffHasPermission(Permission::ORDER_DISCOUNT),
                        $order->currency(),
                    );

                    // D2/D4 (stage 4b-ii) — the "Add a product" section's
                    // own submitted state, read by the SAME pure mapper.
                    // What comes back is only what the submission ASKS FOR
                    // (a variation and a quantity); its price is resolved
                    // live, inside the operation below, so a malformed ask
                    // is refused here and a genuine pricing/catalog refusal
                    // is translated there.
                    $addRequest = OrderEditFormMapper::addLineRequest((array) ($data['add'] ?? []));

                    // A PICK THE ORDER ALREADY SELLS IS A QUANTITY CHANGE ON
                    // THAT LINE, NEVER A SECOND LINE OF THE SAME VARIATION
                    // (refinement of §1/E2's "add a product/variation with a
                    // quantity"): the line IS the order's own §3.13 snapshot,
                    // so merging into it keeps the price those units were
                    // actually sold at. Pricing a fresh line from the LIVE
                    // price list would silently re-price the units the
                    // merchant meant to add — and, once a price list changed,
                    // would put two lines of one variation on one order at two
                    // different prices, which §8's "never a free price
                    // override" leaves no room for.
                    //
                    // The merged quantity is written through the ONE path this
                    // dialog already uses for a line whose quantity changed,
                    // so the stock, the ledger, the totals and the EDITED
                    // event follow the line's own reversal + replacement
                    // (§4.2) exactly as a reduction does — see
                    // foldAddIntoLine() for why the row's own intent and the
                    // add must be combined into ONE change entry.
                    $mergeTarget = $addRequest === null
                        ? null
                        : static::mergeTargetFor(array_values($currentLinesById), $addRequest['variationId']);

                    if ($mergeTarget !== null) {
                        $lineChanges = static::foldAddIntoLine($lineChanges, $mergeTarget, $addRequest['quantity']);
                    }

                    // The ask that still has to be PRICED, as a line of its
                    // own: null when the section was left alone, and null when
                    // it was folded into a line above.
                    $addToPrice = $mergeTarget === null ? $addRequest : null;

                    $delivery = OrderEditFormMapper::deliveryChange(
                        array_combine(OrderEditFormMapper::DELIVERY_FIELDS, array_map(
                            static fn (string $field): ?string => $record->{$field},
                            OrderEditFormMapper::DELIVERY_FIELDS,
                        )),
                        (array) ($data['delivery'] ?? []),
                    );

                    $promotionCode = OrderEditFormMapper::promotionCodeChange(
                        $order->appliedPromotionCode(),
                        $data['promotion_code'] ?? null,
                        (bool) ($data['remove_promotion_code'] ?? false),
                    );
                } catch (InvalidArgumentException) {
                    Notification::make()
                        ->title(__('orders.actions.refused_title'))
                        ->body(__('orders.actions.generic_refusal_body', ['id' => $record->id]))
                        ->danger()
                        ->send();

                    return;
                }

                if ($lineChanges === [] && $addToPrice === null && $delivery === null && $promotionCode->isUnchanged()) {
                    Notification::make()
                        ->title(__('orders.actions.refused_title'))
                        ->body(__('orders.actions.edit_nothing_to_change'))
                        ->danger()
                        ->send();

                    return;
                }

                $staff = app(PanelStaffActor::class)->current();

                static::runOrderAction(
                    $record,
                    $livewire,
                    function () use ($record, $data, $order, $lineChanges, $addToPrice, $delivery, $promotionCode, $staff): void {
                        // The added line is priced HERE, inside the mapped
                        // operation, for two reasons: its price must be the
                        // price at the moment of submission (never one
                        // resolved when the modal was painted), and its own
                        // refusals — an unconfigured price, a variation that
                        // stopped being sellable, insufficient stock from
                        // OrderLineEditor below — belong to the same
                        // translated refusal handling every other refusal on
                        // this action already uses.
                        //
                        // NULL WHEN THE ASK WAS MERGED into a line the order
                        // already sells: there is no new line to price, and
                        // the line's own snapshot price is what the merged
                        // units are counted at.
                        if ($addToPrice !== null) {
                            $lineChanges[] = app(OrderAddLinePricer::class)->pricedChange(
                                variationId: $addToPrice['variationId'],
                                quantity: $addToPrice['quantity'],
                                currency: $order->currency(),
                            );
                        }

                        app(OrderEditor::class)->apply(
                            orderId: (string) $record->id,
                            expectedRevision: (int) ($data['edit_revision'] ?? -1),
                            lineChanges: $lineChanges,
                            delivery: $delivery,
                            promotionCode: $promotionCode,
                            editedBy: $staff !== null ? (string) $staff->id : null,
                            editedByName: $staff?->name,
                            reason: $data['reason'] ?? null,
                            occurredAt: new DateTimeImmutable,
                        );
                    },
                    __('orders.actions.edit_done', ['id' => $record->id]),
                );
            });
    }

    /**
     * The edit dialog's form: line table, delivery, promotion, reason, and
     * the hidden concurrency token. Delivery labels and the delivery-type
     * options are the SAME `orders.fields.*` / `orders.delivery_type_options`
     * keys the read-only Delivery section above already uses — the form is the
     * editable twin of that section, not a second vocabulary — and its
     * street/pickup field visibility mirrors that section's own
     * `delivery_type` conditions.
     *
     * @return array<int, mixed>
     */
    private static function buildEditFormSchema(OrderModel $record): array
    {
        $mayDiscount = static::staffHasPermission(Permission::ORDER_DISCOUNT);
        $view = static::forOrder($record);

        $rows = array_map(static fn (OrderAdminSaleLineView $line): array => [
            'line_id' => $line->id,
            'product' => trim(($line->productName ?? __('orders.not_available')).($line->sku !== null ? " ({$line->sku})" : '')),
            'current_quantity' => (string) $line->quantity,
            'quantity' => $line->quantity,
            'discount' => $line->discretionaryDiscount?->decimalValue() ?? '0.00',
        ], $view->lines);

        $columns = [
            RepeaterTableColumn::make(__('orders.fields.product_name')),
            RepeaterTableColumn::make(__('orders.actions.edit_current_quantity'))->alignEnd(),
            RepeaterTableColumn::make(__('orders.actions.edit_new_quantity'))->alignEnd(),
        ];

        // How many rows of the dialog still carry a quantity above 0 — read from
        // the LIVE form state, not from the stored order. The "add a product"
        // section is a different state key and deliberately does not count.
        $liveLineCount = static fn (Get $get): int => count(array_filter(
            (array) $get('../../lines'),
            static fn (mixed $row): bool => (int) ($row['quantity'] ?? 0) > 0,
        ));

        $cells = [
            Hidden::make('line_id'),
            // A removed row (quantity 0) is struck through and dimmed — the
            // same cue the quantity cell below it gives, no new style.
            TextInput::make('product')->hiddenLabel()->disabled()->dehydrated(false)
                ->extraInputAttributes(static fn (Get $get): array => (int) $get('quantity') === 0
                    ? ['style' => 'text-decoration: line-through; opacity: .5;']
                    : []),
            TextInput::make('current_quantity')->hiddenLabel()->disabled()->dehydrated(false),
            TextInput::make('quantity')
                ->hiddenLabel()
                ->numeric()
                ->integer()
                ->required()
                ->minValue(0)
                ->maxValue(fn (Get $get): int => (int) $get('current_quantity'))
                // Live, so the last-line guard on the remove control follows
                // what the merchant types.
                ->live(onBlur: true),
        ];

        if ($mayDiscount) {
            $columns[] = RepeaterTableColumn::make(__('orders.actions.edit_discount'))->alignEnd();
            $cells[] = TextInput::make('discount')->hiddenLabel()->numeric()->minValue(0);
        }

        $columns[] = RepeaterTableColumn::make(__('orders.actions.edit_remove_line'))->hiddenHeaderLabel();

        // Both actions are bare icons (iconButton() + hiddenLabel(): the label stays
        // as the aria-label and the hover tooltip). The control is appended AFTER the
        // optional discount column so it is always the last cell of the row.
        // REMOVE SETS THE ROW'S QUANTITY TO 0 — it never deletes the row: a row
        // missing from the form state would read as "unchanged" to
        // OrderEditFormMapper::lineChanges() and the item would silently stay.
        // No confirmation modal: nothing is deleted until the dialog's own save.
        $cells[] = Actions::make([
            Action::make('removeLine')
                ->label(__('orders.actions.edit_remove_line'))
                ->icon('heroicon-m-trash')
                ->color('danger')
                ->iconButton()
                ->hiddenLabel()
                ->visible(static fn (Get $get): bool => (int) $get('quantity') > 0)
                ->disabled(static fn (Get $get): bool => (int) $get('quantity') > 0 && $liveLineCount($get) <= 1)
                ->tooltip(static fn (Get $get): string => $liveLineCount($get) <= 1
                    ? __('orders.actions.edit_remove_last_line')
                    : __('orders.actions.edit_remove_line'))
                ->action(static function (Set $set): void {
                    $set('quantity', 0);
                }),
            Action::make('restoreLine')
                ->label(__('orders.actions.edit_restore_line'))
                ->icon('heroicon-m-arrow-uturn-left')
                ->iconButton()
                ->hiddenLabel()
                ->tooltip(__('orders.actions.edit_restore_line'))
                ->visible(static fn (Get $get): bool => (int) $get('quantity') === 0)
                ->action(static function (Get $get, Set $set): void {
                    $set('quantity', (int) $get('current_quantity'));
                }),
        ])->key('lineControls')->alignEnd();

        $isStreet = static fn (Get $get): bool => $get('delivery.delivery_type') === OrderDeliveryType::STREET_ADDRESS->value;
        $isPickup = static fn (Get $get): bool => $get('delivery.delivery_type') === OrderDeliveryType::PICKUP_POINT->value;

        return [
            Hidden::make('edit_revision')->default((int) $record->edit_revision),
            Section::make(__('orders.sections.lines'))
                ->description(__('orders.actions.edit_lines_hint'))
                ->schema([
                    Repeater::make('lines')
                        ->hiddenLabel()
                        ->table($columns)
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->default($rows)
                        ->schema($cells),
                ]),
            Section::make(__('orders.actions.edit_add_heading'))
                ->description(__('orders.actions.edit_add_hint'))
                ->schema([
                    Select::make('add.variation_id')
                        ->label(__('orders.fields.product_name'))
                        ->placeholder(__('orders.actions.edit_add_product_placeholder'))
                        // The search and every label come from ONE service
                        // (OrderLineProductSearch), whose matching is
                        // ProductResource's own — see that class's docblock.
                        // It returns VARIATIONS, each keyed by priceableId,
                        // which is exactly what an ADD entry names.
                        ->searchable()
                        ->getSearchResultsUsing(static fn (string $search): array => app(OrderLineProductSearch::class)->results($search))
                        // A value that was picked before a submission that
                        // failed validation ELSEWHERE on this form must
                        // render its label again, never a bare id. The
                        // value arrives as a string OR an int (Filament's
                        // own option state cast / PHP's numeric array
                        // keys), so it is normalised rather than assumed.
                        ->getOptionLabelUsing(static fn (mixed $value): ?string => is_string($value) || is_int($value)
                            ? app(OrderLineProductSearch::class)->label($value)
                            : null)
                        // Live, because the price below is resolved for the
                        // chosen variation — the merchant sees what they are
                        // about to add before they add it.
                        ->live(),
                    TextInput::make('add.quantity')
                        ->label(__('orders.actions.edit_add_quantity'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->default(1)
                        ->required()
                        // The resolved price can depend on the quantity
                        // (PriceListItem::minQuantity() tiers), so the
                        // preview re-resolves when the quantity is left.
                        ->live(onBlur: true),
                    // A render-time display, NOT a state-bound entry:
                    // Filament\Schemas\Components\Text's own content closure
                    // is evaluated while the component is RENDERED, so the
                    // amount reflects the variation picked a moment ago in
                    // the very same round trip. A TextEntry with ->state()
                    // would instead write its value once, while the mounted
                    // action's schema is BUILT (verified: the action's schema
                    // is cached during bootedInteractsWithActions(), before
                    // the round trip's own state update lands, which leaves
                    // the first render one step behind).
                    Text::make(static fn (Get $get): HtmlString => static::addLinePricePreview($get, $record))
                        ->key('add.price')
                        ->visible(static fn (Get $get): bool => filled($get('add.variation_id'))),
                ])
                ->columns(3),
            Section::make(__('orders.sections.delivery'))
                ->schema([
                    Select::make('delivery.delivery_type')
                        ->label(__('orders.fields.delivery_type'))
                        ->options([
                            OrderDeliveryType::STREET_ADDRESS->value => __('orders.delivery_type_options.street_address'),
                            OrderDeliveryType::PICKUP_POINT->value => __('orders.delivery_type_options.pickup_point'),
                        ])
                        ->default($record->delivery_type)
                        ->required()
                        ->live(),
                    TextInput::make('delivery.recipient_name')->label(__('orders.fields.recipient_name'))->default($record->recipient_name)->required(),
                    TextInput::make('delivery.phone')->label(__('orders.fields.phone'))->default($record->phone)->required(),
                    // The country belongs to BOTH delivery types (owner decision D1), so it
                    // is always shown, and picked from the same list the domain's HTTP layer
                    // validates against. It is required, EXCEPT on a historical pickup-point
                    // order that never had one: that order must stay editable in its lines
                    // and promotion without being forced to invent a country, and if its
                    // delivery snapshot IS changed the domain still refuses a blank country.
                    Select::make('delivery.country')
                        ->label(__('orders.fields.country'))
                        ->options(fn (): array => CountryNames::forLocale(app(StoreLocale::class)->current()))
                        ->searchable()
                        ->default($record->country)
                        ->rule(new KnownCountryCode('delivery.country.invalid'))
                        ->required(fn (Get $get): bool => $record->country !== null || $isStreet($get)),
                    TextInput::make('delivery.city')->label(__('orders.fields.city'))->default($record->city)->visible($isStreet)->required(),
                    TextInput::make('delivery.postal_code')->label(__('orders.fields.postal_code'))->default($record->postal_code)->visible($isStreet),
                    TextInput::make('delivery.address_line_1')->label(__('orders.fields.address_line_1'))->default($record->address_line_1)->visible($isStreet)->required(),
                    TextInput::make('delivery.address_line_2')->label(__('orders.fields.address_line_2'))->default($record->address_line_2)->visible($isStreet),
                    TextInput::make('delivery.carrier_code')->label(__('orders.fields.carrier_code'))->default($record->carrier_code)
                        ->required($isPickup),
                    TextInput::make('delivery.pickup_point_reference')->label(__('orders.fields.pickup_point_reference'))->default($record->pickup_point_reference)->visible($isPickup)->required(),
                    TextInput::make('delivery.settlement')->label(__('orders.fields.settlement'))->default($record->settlement)->visible($isPickup)->required(),
                ])
                ->columns(2),
            Section::make(__('orders.sections.promotion'))
                ->schema([
                    TextInput::make('promotion_code')
                        ->label(__('orders.actions.edit_promotion_code'))
                        ->helperText(__('orders.actions.edit_promotion_code_hint'))
                        ->default($record->applied_promotion_code),
                    Toggle::make('remove_promotion_code')
                        ->label(__('orders.actions.edit_remove_promotion_code'))
                        ->default(false)
                        ->visible($record->applied_promotion_code !== null),
                ]),
            Textarea::make('reason')->label(__('orders.actions.reason_label')),
        ];
    }

    /**
     * D1 (order-lifecycle-design.md §8.1's "Add internal note", §10 stage
     * 7c-3) — "the page's third write, and the one action here that
     * cannot refuse": no status condition at all in ->visible() (always
     * offered to anyone with ORDER_MANAGE, regardless of the order's own
     * status), and the note field is the ONE genuinely ->required() field
     * on this whole page — every other action's own note/reason is free
     * text and optional (§5.2); an empty note is nothing to record at
     * all, not a valid "note added" event.
     *
     * NO EXCEPTION BEYOND \Throwable IS REALISTICALLY REACHABLE HERE,
     * CONFIRMED RATHER THAN ASSUMED (D1 asked to report one if found): a
     * first draft suspected a whitespace-only note (" ") would satisfy
     * Filament's own ->required() rule — which is not empty/null — and
     * still reach OrderEventRecorder::record()'s own `trim($reason) ===
     * ''` guard for NOTE_ADDED. Tested directly against the real action:
     * it does not. Filament's own required validation refuses a
     * whitespace-only Textarea submission too (the action stays mounted
     * with a validation error, confirmed via its own mountedActions
     * state — never reaching this closure at all), so the domain's own
     * blank-reason guard is unreachable through this action, exactly the
     * "cannot refuse" §8.1 itself describes.
     */
    public static function addNoteAction(): Action
    {
        return Action::make('add_note')
            ->label(__('orders.actions.add_note'))
            ->color('gray')
            ->icon('heroicon-o-pencil-square')
            ->requiresConfirmation()
            ->modalHeading(fn (OrderModel $record): string => __('orders.actions.add_note_heading', ['id' => $record->id]))
            ->modalDescription(fn (OrderModel $record): string => __('orders.actions.add_note_description', ['id' => $record->id]))
            ->schema([
                Textarea::make('note')->label(__('orders.actions.note_field_label'))->required(),
            ])
            ->visible(fn (): bool => static::staffHasPermission(Permission::ORDER_MANAGE))
            ->action(function (array $data, OrderModel $record, $livewire): void {
                static::runOrderAction(
                    $record,
                    $livewire,
                    fn () => app(OrderNoteRecorder::class)->record((string) $record->id, $data['note'], new DateTimeImmutable),
                    __('orders.actions.add_note_done'),
                );
            });
    }

    /**
     * The dialog's submission as a RefundRequest (refunds R3 part 2): the entered goods amounts, the shipping
     * refund (or reduction), the deduction with its reason, the channel, the announced day and the operation
     * key. A blank goods box is the computed share. See RefundDialog::requestFrom().
     *
     * (D3's old "money permission clause" that hid cancel/return from staff without the DEFAULT channel's
     * permission is gone: the dialog is offered, the channel options follow the permissions, and staff with
     * neither channel see a notice and no submit button — hasNoRefundChannel().)
     */
    private static function refundRequestFrom(array $data, OrderModel $record, string $kind): RefundRequest
    {
        $reader = app(RefundFormReader::class);
        $lines = array_values(array_filter(static::forOrder($record)->lines, static fn (OrderAdminSaleLineView $line): bool => $line->remainingReturnable > 0));
        $context = $reader->forOrder((string) $record->id, array_map(static fn (OrderAdminSaleLineView $line): string => (string) $line->id, $lines), $kind === RefundDialog::RETURN);

        return RefundDialog::requestFrom($data, $lines, $context, $kind, $reader);
    }

    /** A settled payment and a staff member who may use neither payout channel: the dialog tells them so and cannot submit. */
    private static function hasNoRefundChannel(OrderModel $record, bool $requireSettled = true): bool
    {
        // The permission of a channel is RefundPermissionPolicy::permissionFor() — the one derivation the service enforces.
        $mayUse = static fn (RefundChannel $channel): bool => static::staffHasPermission(RefundPermissionPolicy::permissionFor($channel));

        return (! $requireSettled || (static::forOrder($record)->latestPayment?->isSettled() ?? false))
            && ! $mayUse(RefundChannel::CASH)
            && ! $mayUse(RefundChannel::BANK);
    }

    /** @var \WeakMap<OrderModel, array<string, mixed>>|null */
    private static ?\WeakMap $refundsViews = null;

    /**
     * One read per record INSTANCE (a WeakMap, like OrderAdminReader's own per-instance cache): Filament asks
     * for the section's visibility more than once per render, and the query budget of the page is tight. Keyed
     * by the instance, never by the order id, so a later request — which rehydrates a NEW instance — always
     * reads fresh and a refund paid out meanwhile cannot show as owed.
     *
     * @return array{currency: string, figures: array{paid_in: int, paid_out: int, owed: int, still_refundable: int}, rows: list<array<string, mixed>>}
     */
    private static function refundsView(OrderModel $record): array
    {
        static::$refundsViews ??= new \WeakMap();

        return static::$refundsViews[$record] ??= app(OrderRefundsReader::class)->forOrder((string) $record->id, (string) $record->currency);
    }

    private static function formatMinor(int $minor, string $currency): string
    {
        return app(PriceDisplayFormatter::class)->format(Money::fromMinorUnits($minor, $currency)->decimalValue(), Currency::of($currency));
    }

    /**
     * The refunds section's content: the four figures (§7.2.5), then one block per refund,
     * newest first, each with the actions that apply to it.
     *
     * @return list<\Filament\Schemas\Components\Component>
     */
    private static function refundsSchema(OrderModel $record): array
    {
        $view = static::refundsView($record);
        $currency = $view['currency'];
        $money = static fn (int $minor): string => static::formatMinor($minor, $currency);

        $components = [
            Section::make()
                ->schema(array_map(
                    static fn (string $figure): TextEntry => TextEntry::make('refund_figure_'.$figure)
                        ->label(__('orders.refunds.figures.'.$figure))
                        ->getStateUsing(fn (): string => $money($view['figures'][$figure])),
                    ['paid_in', 'paid_out', 'owed', 'still_refundable'],
                ))
                ->columns(4),
        ];

        foreach ($view['rows'] as $row) {
            $id = $row['id'];
            $stateKey = $row['is_legacy'] ? 'legacy_paid_out' : $row['status'];

            $components[] = Section::make(__('orders.refunds.refund_heading', ['id' => $id]))
                ->schema([
                    TextEntry::make("refund_{$id}_status")
                        ->label(__('orders.refunds.fields.status'))
                        ->badge()
                        ->getStateUsing(fn (): string => __('orders.refunds.status.'.$stateKey))
                        ->color(fn (): string => match ($stateKey) {
                            'owed', 'requested' => 'warning',
                            'paid_out', 'completed' => 'success',
                            'cancelled', 'failed' => 'danger',
                            default => 'gray',   // a legacy paid-out row: money long since returned, quietly
                        }),
                    TextEntry::make("refund_{$id}_channel")
                        ->label(__('orders.refunds.fields.channel'))
                        ->getStateUsing(fn (): string => Lang::has('orders.refunds.channel.'.$row['channel']) ? __('orders.refunds.channel.'.$row['channel']) : $row['channel']),
                    TextEntry::make("refund_{$id}_goods")
                        ->label(__('orders.refunds.fields.goods'))
                        ->getStateUsing(fn (): string => $money($row['goods'])),
                    TextEntry::make("refund_{$id}_shipping")
                        ->label(__('orders.refunds.fields.shipping'))
                        ->getStateUsing(fn (): string => $money($row['shipping'])),
                    TextEntry::make("refund_{$id}_adjustment")
                        ->label(__('orders.refunds.fields.adjustment'))
                        ->visible($row['adjustment'] !== 0)
                        ->getStateUsing(fn (): string => $money($row['adjustment'])),
                    TextEntry::make("refund_{$id}_deduction")
                        ->label(__('orders.refunds.fields.deduction'))
                        ->visible($row['deduction'] !== 0)
                        ->getStateUsing(fn (): string => '-'.$money($row['deduction'])),
                    TextEntry::make("refund_{$id}_deduction_reason")
                        ->label(__('orders.refunds.fields.deduction_reason'))
                        ->visible(filled($row['deduction_reason']))
                        ->getStateUsing(fn (): ?string => $row['deduction_reason']),
                    TextEntry::make("refund_{$id}_total")
                        ->label(__('orders.refunds.fields.total'))
                        ->weight('bold')
                        ->getStateUsing(fn (): string => $money($row['total'])),
                    TextEntry::make("refund_{$id}_recorded_at")
                        ->label(__('orders.refunds.fields.recorded_at'))
                        ->visible(filled($row['recorded_at']))
                        ->getStateUsing(fn (): ?string => $row['recorded_at'])
                        ->dateTime(),
                    TextEntry::make("refund_{$id}_recorded_by")
                        ->label(__('orders.refunds.fields.recorded_by'))
                        ->visible(filled($row['recorded_by']))
                        ->getStateUsing(fn (): ?string => $row['recorded_by']),
                    TextEntry::make("refund_{$id}_reason")
                        ->label(__('orders.refunds.fields.reason'))
                        ->visible(filled($row['reason']))
                        ->getStateUsing(fn (): ?string => $row['reason']),
                    TextEntry::make("refund_{$id}_paid_out_at")
                        ->label(__('orders.refunds.fields.paid_out_at'))
                        ->visible(filled($row['paid_out_at']))
                        ->getStateUsing(fn (): ?string => $row['paid_out_at'])
                        ->dateTime(),
                    TextEntry::make("refund_{$id}_paid_out_by")
                        ->label(__('orders.refunds.fields.paid_out_by'))
                        ->visible(filled($row['paid_out_by']))
                        ->getStateUsing(fn (): ?string => $row['paid_out_by']),
                    TextEntry::make("refund_{$id}_paid_out_reference")
                        ->label(__('orders.refunds.fields.paid_out_reference'))
                        ->visible(filled($row['paid_out_reference']))
                        ->getStateUsing(fn (): ?string => $row['paid_out_reference']),
                    TextEntry::make("refund_{$id}_paid_out_note")
                        ->label(__('orders.refunds.fields.paid_out_note'))
                        ->visible(filled($row['paid_out_note']))
                        ->getStateUsing(fn (): ?string => $row['paid_out_note']),
                    TextEntry::make("refund_{$id}_cancelled_at")
                        ->label(__('orders.refunds.fields.cancelled_at'))
                        ->visible(filled($row['cancelled_at']))
                        ->getStateUsing(fn (): ?string => $row['cancelled_at'])
                        ->dateTime(),
                    TextEntry::make("refund_{$id}_cancelled_by")
                        ->label(__('orders.refunds.fields.cancelled_by'))
                        ->visible(filled($row['cancelled_by']))
                        ->getStateUsing(fn (): ?string => $row['cancelled_by']),
                    TextEntry::make("refund_{$id}_cancelled_reason")
                        ->label(__('orders.refunds.fields.cancelled_reason'))
                        ->visible(filled($row['cancelled_reason']))
                        ->getStateUsing(fn (): ?string => $row['cancelled_reason']),
                    Actions::make([
                        static::markRefundPaidOutAction($id, $row['channel'], $row['total'], $currency),
                        static::cancelRefundAction($id, $row['channel']),
                    ])->key('refund_actions_'.$id),
                ])
                ->columns(4);
        }

        return $components;
    }

    /**
     * Visible only for an OWED refund, and only to staff holding the permission of the refund's OWN
     * channel, read through RefundPermissionPolicy::permissionFor() — the very derivation the service
     * enforces, so the panel and RefundStatusChanger can never disagree. The state is a FRESH read of
     * the refund, not the schema's snapshot, so a refund paid out in another tab loses its buttons.
     */
    private static function refundActionAllowed(string $refundId, string $channel): bool
    {
        return DB::table('payment_refunds')->where('id', $refundId)->value('status') === 'owed'
            && static::staffHasPermission(RefundPermissionPolicy::permissionFor(RefundChannel::from($channel)));
    }

    public static function markRefundPaidOutAction(string $refundId, string $channel, int $totalMinor, string $currency): Action
    {
        return Action::make('mark_refund_paid_out')
            ->label(__('orders.refunds.mark_paid_out.label'))
            ->color('success')
            ->icon('heroicon-o-banknotes')
            ->modalHeading(__('orders.refunds.mark_paid_out.heading', ['id' => $refundId]))
            ->modalDescription(__('orders.refunds.mark_paid_out.description', ['amount' => static::formatMinor($totalMinor, $currency)]))
            ->schema([
                DateTimePicker::make('paid_out_at')
                    ->label(__('orders.refunds.mark_paid_out.paid_out_at'))
                    ->required()
                    ->seconds(false)
                    ->default(fn (): string => now()->format('Y-m-d H:i'))
                    // The picker holds the merchant's LOCAL time (Filament hydrates it from, and dehydrates it back to, the app
                    // timezone), and the rule compares the typed value with this one as plain text: so "now" must be given in
                    // the same timezone, or every payout time after midnight-UTC-minus-offset is refused as "in the future".
                    ->maxDate(fn (): Carbon => now(FilamentTimezone::get())),
                TextInput::make('reference')
                    ->label(__('orders.refunds.mark_paid_out.reference'))
                    ->required($channel === RefundChannel::BANK->value)
                    ->maxLength(255),
                Textarea::make('note')
                    ->label(__('orders.refunds.mark_paid_out.note'))
                    ->rows(2),
                // One key per opening of the dialog: a double submit is one payout (§7.2.3).
                Hidden::make('operation_key')->default(fn (): string => (string) Str::uuid()),
            ])
            ->visible(fn (): bool => static::refundActionAllowed($refundId, $channel))
            ->action(function (array $data, OrderModel $record, $livewire) use ($refundId): void {
                static::runOrderAction(
                    $record,
                    $livewire,
                    fn () => app(RefundStatusChanger::class)->markPaidOut(
                        $refundId,
                        // The action receives the value already converted back to the APP timezone (UTC) by Filament
                        // (the picker itself shows the store timezone: ApplyStoreTimezone -> FilamentTimezone).
                        new DateTimeImmutable((string) $data['paid_out_at'], new \DateTimeZone(config('app.timezone'))),
                        $data['reference'] ?? null,
                        $data['note'] ?? null,
                        now()->toDateTimeImmutable(),
                        is_string($data['operation_key'] ?? null) && $data['operation_key'] !== '' ? $data['operation_key'] : null,
                    ),
                    __('orders.refunds.mark_paid_out.done', ['id' => $refundId]),
                );
            });
    }

    public static function cancelRefundAction(string $refundId, string $channel): Action
    {
        return Action::make('cancel_refund')
            ->label(__('orders.refunds.cancel.label'))
            ->color('danger')
            ->icon('heroicon-o-x-circle')
            ->modalHeading(__('orders.refunds.cancel.heading', ['id' => $refundId]))
            ->schema([
                Text::make(__('orders.refunds.cancel.warning')),
                Textarea::make('reason')
                    ->label(__('orders.refunds.cancel.reason'))
                    ->required()
                    ->rows(2),
                Hidden::make('operation_key')->default(fn (): string => (string) Str::uuid()),
            ])
            ->visible(fn (): bool => static::refundActionAllowed($refundId, $channel))
            ->action(function (array $data, OrderModel $record, $livewire) use ($refundId): void {
                static::runOrderAction(
                    $record,
                    $livewire,
                    fn () => app(RefundStatusChanger::class)->cancelOwed(
                        $refundId,
                        (string) $data['reason'],
                        now()->toDateTimeImmutable(),
                        is_string($data['operation_key'] ?? null) && $data['operation_key'] !== '' ? $data['operation_key'] : null,
                    ),
                    __('orders.refunds.cancel.done', ['id' => $refundId]),
                );
            });
    }

    /**
     * D4/§0 item 7 — the cancel/return dialog's shared line-block
     * builder. ONE FIXED, DYNAMICALLY-GENERATED SET, NOT A Repeater:
     * every candidate line (remainingReturnable > 0) is already known in
     * full at render time from the SAME batched read stage 7c-1 built
     * (OrderAdminSaleLineView::remainingReturnable), so there is nothing
     * for the merchant to add or remove and no reason to pay for
     * Repeater's own add/delete/reorder machinery and its item-key
     * indirection (a Repeater's own item state is keyed by an
     * auto-generated key, not the line's own id, which would need a
     * hidden per-item field just to read it back) — a plain Fieldset per
     * line, its own field NAMES built directly from the line's real id
     * (`quantity.{$id}`, `restock.{$id}`), is both simpler and reads the
     * submission back with zero indirection. Filament\Forms\Components\
     * Repeater IS used elsewhere in this codebase (ProductResource's own
     * variations, an open-ended user-add/remove collection) — read as
     * the style precedent §0 asked for, and confirmed to be the WRONG
     * shape for this fixed, render-time-known set.
     *
     * The quantity display uses TextEntry (an Infolist component, legal
     * inside a Schema action's ->schema() array in this unified v5.8.1
     * Schema system — Fieldset itself lives in Filament\Schemas\
     * Components for the same reason) rather than Filament\Forms\
     * Components\Placeholder, confirmed by reading Placeholder's own
     * installed source: `@deprecated Use TextEntry with the state()
     * method instead`.
     *
     * A line with remainingReturnable === 0 is skipped entirely — "there
     * is nothing left to ask about it" (D4).
     *
     * @return array<int, Fieldset|Textarea>
     */
    private static function buildLineFormSchema(OrderModel $record, bool $editableQuantity, bool $showRestockToggle): array
    {
        $kind = $editableQuantity ? RefundDialog::RETURN : RefundDialog::CANCEL;
        $reader = app(RefundFormReader::class);
        $shown = array_values(array_filter(static::forOrder($record)->lines, static fn (OrderAdminSaleLineView $line): bool => $line->remainingReturnable > 0));
        // The money of this order, read once as the dialog opens: the rooms come from the service's own cap source.
        $context = $reader->forOrder((string) $record->id, array_map(static fn (OrderAdminSaleLineView $line): string => (string) $line->id, $shown), $kind === RefundDialog::RETURN);
        $blocks = [];

        // A RETURN shows the facts a shop's own policy depends on (delivery, earlier returns) — facts only (§7.2.6).
        if ($kind === RefundDialog::RETURN) {
            $blocks[] = RefundDialog::factsPanel((string) $record->id);
        }

        foreach ($shown as $line) {
            $quantity = $editableQuantity
                ? TextInput::make("quantity.{$line->id}")
                    ->label(__('orders.actions.quantity_label'))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue($line->remainingReturnable)
                    ->default(null)
                : TextEntry::make("quantity_display.{$line->id}")
                    ->label(__('orders.actions.quantity_label'))
                    ->state((string) $line->remainingReturnable);

            $fields = [$editableQuantity && $context->mode !== \App\Services\RefundFormContext::NONE ? RefundDialog::followQuantity($quantity, $line, $reader) : $quantity];

            // The goods amount of the line: the computed share, editable on a settled payment, read-only on a pending one.
            if ($context->mode !== \App\Services\RefundFormContext::NONE) {
                $fields[] = RefundDialog::goodsField($line, $context, $kind, $reader);
            }

            if ($showRestockToggle) {
                $fields[] = Toggle::make("restock.{$line->id}")
                    ->label(__('orders.actions.restock_label'))
                    ->default(true);
            }

            $blocks[] = Fieldset::make($line->productName ?? $line->sku ?? __('orders.not_available'))
                ->schema($fields);
        }

        array_push($blocks, ...RefundDialog::moneySection($context, $shown, $kind, $reader));

        // The day the customer announced the return — a calendar day, optional, returns only (§7.2.6).
        if ($kind === RefundDialog::RETURN) {
            $blocks[] = RefundDialog::announcedDayField();
        }

        $blocks[] = Textarea::make('reason')->label(__('orders.actions.reason_label'))->maxLength(RefundDialog::TEXT_MAX_LENGTH);

        // ONE KEY PER OPENING OF THE DIALOG (shipping-domain-design.md §7.2.3): generated
        // when the form is filled, submitted with it, so a double click or a retry of
        // the same submission is one cancel/return, not two.
        $blocks[] = Hidden::make('operation_key')->default(fn (): string => (string) Str::uuid());

        return $blocks;
    }

    /**
     * cancelAction()'s own submission mapping (D5) — every line THIS
     * form actually showed (remainingReturnable > 0), keyed by id, `true`
     * for an absent entry (Filament's own Toggle omits an unchecked
     * value from $data only in some circumstances — reading with a
     * default rather than assuming presence either way is the safe
     * reading of "on by default").
     *
     * @return array<string, bool>
     */
    private static function restockOverridesFromData(array $data, OrderAdminOrderView $view): array
    {
        $overrides = [];

        foreach ($view->lines as $line) {
            if ($line->remainingReturnable <= 0) {
                continue;
            }

            $overrides[$line->id] = (bool) ($data['restock'][$line->id] ?? true);
        }

        return $overrides;
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
     *  - ReturnExceedsRemainingQuantityException (§10 stage 7c-2, from
     *    ReturnGoodsRecorder via recordReturn()): a translated message
     *    built from its own requestedQuantity()/remainingQuantity(),
     *    naming the LINE by product name/sku — resolved from
     *    forOrder($record)->lines, already in hand, no second query — or
     *    this page's own "not available" wording if the id is somehow not
     *    among them (a page rendered against a different order's state).
     *  - OrderAddLineRefusedException (stage 4b-ii, from OrderAddLinePricer
     *    via editAction()): the variation the merchant picked cannot be
     *    added — it vanished, is not effectively purchasable, or its
     *    product is not active. One translated sentence: to the merchant
     *    those are one fact, and all of them mean "nothing to add".
     *  - PriceNotConfiguredException (Pricing, from the same: Pricing's own
     *    exception, reused rather than re-detected): the chosen variation
     *    has no price in the order's currency, so it can never become a
     *    line — the same fact the dialog already shows next to the picker.
     *  - InsufficientStockException (Inventory, from OrderLineEditor's own
     *    stock step): the added quantity cannot be reserved. Only an ADD
     *    can raise this from this action (a quantity can be reduced here,
     *    never raised), so the sentence names the new item, and says the
     *    same "nothing was saved" the rest of this page's refusals do.
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
        } catch (RefundCapExceededException|RefundPermissionDeniedException|OperationKeyReusedException|PendingPaymentRefundRuleException|RefundTransitionRefusedException|MoneyOnlyRefundRefusedException|ReturnAnnouncedDateException $e) {
            // The refund caps, the money permission, a reused operation key, a money-only refusal and an
            // impossible announced day all carry their own translated sentence: shown as a refusal, never a 500.
            Notification::make()
                ->title(__('orders.actions.refused_title'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        } catch (PendingShippingInvariantBrokenException $e) {
            // A broken invariant is not something the merchant can correct in a form: a plain notice, the detail in the log.
            Log::error('Refund refused: a pending-payment invariant is broken.', ['order_id' => $e->orderId, 'payment_id' => $e->paymentId, 'reduced_so_far_minor' => $e->reducedSoFarMinor]);

            Notification::make()
                ->title(__('orders.actions.refused_title'))
                ->body(__('orders.refunds.invariant_broken'))
                ->danger()
                ->send();

            return;
        } catch (ReturnExceedsRemainingQuantityException $e) {
            $line = null;

            foreach (static::forOrder($record)->lines as $candidate) {
                if ($candidate->id === $e->originatingSaleLineId()) {
                    $line = $candidate;

                    break;
                }
            }

            Notification::make()
                ->title(__('orders.actions.refused_title'))
                ->body(__('orders.actions.return_exceeds_remaining_body', [
                    'line' => $line?->productName ?? $line?->sku ?? __('orders.not_available'),
                    'requested' => $e->requestedQuantity(),
                    'remaining' => $e->remainingQuantity(),
                ]))
                ->danger()
                ->send();

            return;
        } catch (StaleOrderEditException $e) {
            // The order changed since the edit form opened: no field-by-field
            // diff, an honest "start again".
            Notification::make()
                ->title(__('orders.actions.refused_title'))
                ->body(__('orders.actions.edit_stale_body', [
                    'id' => $record->id,
                    'expected' => $e->expectedRevision(),
                    'actual' => $e->actualRevision(),
                ]))
                ->danger()
                ->send();

            return;
        } catch (OrderNotEditableException $e) {
            // Two different facts, two different sentences: money already
            // captured (use cancel/return) versus a status that no longer allows it.
            Notification::make()
                ->title(__('orders.actions.refused_title'))
                ->body($e->isBecauseOfSettledPayment()
                    ? __('orders.actions.edit_not_editable_payment_body', ['id' => $record->id])
                    : __('orders.actions.edit_not_editable_status_body', [
                        'id' => $record->id,
                        'status' => static::optionLabel('status', $e->status()->value),
                    ]))
                ->danger()
                ->send();

            return;
        } catch (PromotionNoLongerValidException $e) {
            $reason = $e->reason();

            Notification::make()
                ->title(__('orders.actions.refused_title'))
                ->body(__('orders.actions.edit_promotion_invalid_body', [
                    'code' => $e->promotionCode(),
                    'reason' => $reason !== null && Lang::has('orders.promotion_refusal_reasons.'.$reason)
                        ? __('orders.promotion_refusal_reasons.'.$reason)
                        : ($reason ?? '-'),
                ]))
                ->danger()
                ->send();

            return;
        } catch (OrderAddLineRefusedException) {
            Notification::make()
                ->title(__('orders.actions.refused_title'))
                ->body(__('orders.actions.edit_add_unavailable_body'))
                ->danger()
                ->send();

            return;
        } catch (PriceNotConfiguredException) {
            Notification::make()
                ->title(__('orders.actions.refused_title'))
                ->body(__('orders.actions.edit_add_no_price_body'))
                ->danger()
                ->send();

            return;
        } catch (InsufficientStockException) {
            Notification::make()
                ->title(__('orders.actions.refused_title'))
                ->body(__('orders.actions.edit_add_insufficient_stock_body'))
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
     * The status badge's color, shared by the table column and the
     * infolist entry (ONE match, never two copies) — mirrors
     * ProductResource's own status column precedent. PLACED/CONFIRMED/
     * SHIPPED are all 'gray' (still in progress, no merchant-actionable
     * outcome yet); DELIVERED is the one 'success' outcome; CANCELLED is
     * 'danger'; REFUNDED is 'warning' (money moved back out, distinct from
     * a plain cancellation).
     */
    private static function statusColor(string $state): string
    {
        return match (OrderStatus::from($state)) {
            OrderStatus::PLACED, OrderStatus::CONFIRMED, OrderStatus::SHIPPED => 'gray',
            OrderStatus::DELIVERED => 'success',
            OrderStatus::CANCELLED => 'danger',
            OrderStatus::REFUNDED => 'warning',
        };
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
     * @param  string[]  $values
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
     * The items, one entry per line: the line's whole BLOCK as markup (lineBlockHtml(), every interpolated piece escaped
     * there). One OrderAdminReader read backs the whole list — no per-line query. (Before the order-view polish this
     * was the row data of a seven-column table; the unit cost never was, and still is not, shown for any role — a
     * per-line cost on an order screen is margin analysis by another name.)
     *
     * @return array<int, array{block: string}>
     */
    private static function lineRows(OrderModel $record): array
    {
        return array_map(
            static fn (OrderAdminSaleLineView $line): array => ['block' => static::lineBlockHtml($line)],
            static::forOrder($record)->lines,
        );
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
     * The ONE current line a pick may be merged into, or null when the ask
     * must become a line of its OWN — the "add a product" section's real
     * semantics for a variation the order already sells (stage 4b-ii
     * refinement).
     *
     * EXACTLY ONE MATCH, OR NO MERGE: two current lines of one variation is a
     * state a merchant genuinely reaches (add the same variation twice with
     * different quantities — the second of which was a line of its own
     * precisely because the first existed), and "which of the two did you
     * mean" is not a question this dialog can answer. Merging into either
     * would be a coin toss on the merchant's own money, so the ask falls back
     * to a NEW line (the behaviour every add had before this refinement) and
     * the merchant keeps the Lines section below for reducing the one they
     * actually meant.
     *
     * @param  list<SaleLine>  $currentLines
     */
    private static function mergeTargetFor(array $currentLines, string $variationId): ?SaleLine
    {
        $matches = [];

        foreach ($currentLines as $line) {
            if ($line->priceableId() === $variationId) {
                $matches[] = $line;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * The same choice, resolved from the ORDER rather than from a list the
     * caller already holds — the price preview's own entry point, which
     * renders before any submission exists.
     *
     * REUSES OrderCurrentLinesResolver, deliberately: it is the page's own
     * answer to "what does this order sell now", the very one the dialog's
     * seed and editAction()'s line mapper read, so a preview can never
     * disagree with the write about which line an add would land on. A pick
     * for something the order does NOT sell — the commoner case — pays this
     * resolve and finds nothing, then resolves the live price exactly as it
     * did before: it costs reads, never correctness.
     */
    private static function mergeTargetForOrder(OrderModel $record, string $variationId): ?SaleLine
    {
        $order = app(OrderRepository::class)->findById((string) $record->id);

        if ($order === null) {
            return null;
        }

        $lines = [];

        foreach (app(OrderCurrentLinesResolver::class)->resolve($order) as $line) {
            $lines[] = $line;
        }

        return static::mergeTargetFor($lines, $variationId);
    }

    /**
     * The submission's own change list with the add FOLDED INTO the row it
     * belongs to — or a plain new entry when the row the merchant touched is
     * not the line the add merges into.
     *
     * THE ROW'S OWN INTENT AND THE ADD MUST BE COMBINED, not left as two
     * changes naming one line: OrderLineEditor refuses two changes of the same
     * kind on one line outright ("a repeat would silently make one of the two
     * win"), and a "remove" composes with nothing at all. The row's intent has
     * already been resolved AT THE LINE'S OWN SNAPSHOT PRICE by
     * OrderEditFormMapper::lineChanges() — a row left alone still resolves to
     * its own current quantity — so the combined entry is defined by the
     * ANSWER to "how many units of this line should the order end up with",
     * never by which of the two paths produced it:
     *
     *  - the row's requested quantity plus the added units, when that is a
     *    real change;
     *  - NOTHING AT ALL when the two add up to the line's current quantity —
     *    a row emptied (or reduced) and then given exactly as many of the same
     *    variation is not an edit, and writing it would take stock and put it
     *    straight back in the same breath. Dropping the entry is what lets the
     *    dialog's own "nothing was changed" refusal answer honestly.
     *
     * A ROW EMPTIED AND THEN GIVEN FEWER UNITS IS STILL THAT LINE, because a
     * "remove" of a line the merchant is immediately re-adding to is plainly
     * not what they asked for: the entry becomes change_quantity and the line
     * survives at the quantity they named, at its own snapshot price.
     *
     * A "discount" entry for the same line is left untouched — change_quantity
     * plus discount is the ONE legal pair (§4.2), and it is exactly what a row
     * that was both reduced and discounted already produces. It is SKIPPED,
     * never read for a quantity, so a row the merchant discounted in this same
     * submission keeps its discount beside the folded quantity (see the guard
     * in the loop below).
     *
     * @param  array<int, array<string, mixed>>  $lineChanges  OrderEditFormMapper::lineChanges()'s own output
     * @return array<int, array<string, mixed>>
     */
    private static function foldAddIntoLine(array $lineChanges, SaleLine $target, int $addedQuantity): array
    {
        $targetId = (string) $target->id();
        $currentQuantity = $target->quantity();
        $rowQuantity = null;

        foreach ($lineChanges as $key => $change) {
            $origin = $change['originatingLine'] ?? null;

            if (! $origin instanceof SaleLine || (string) $origin->id() !== $targetId) {
                continue;
            }

            // A "discount" entry for this same line is NOT consumed here. It
            // carries no quantity at all, and change_quantity plus discount is
            // the ONE legal pair on one line (§4.2) — the very pair a row that
            // was both reduced and discounted already produces. Reading it as
            // "the row asked for 0 units" would drop the merchant's discount
            // and land the added units on a quantity no row ever named.
            if (($change['change'] ?? null) === 'discount') {
                continue;
            }

            // The row's own answer for this line: an emptied row asked for
            // zero units, a reduced one for the quantity it shows, and a row
            // left alone produced no entry at all (handled below).
            $rowQuantity = ($change['change'] ?? null) === 'remove' ? 0 : (int) ($change['quantity'] ?? 0);

            unset($lineChanges[$key]);
        }

        $rowQuantity ??= $currentQuantity;

        $newQuantity = $rowQuantity + $addedQuantity;

        if ($newQuantity === $currentQuantity) {
            return array_values($lineChanges);
        }

        $lineChanges[] = [
            'change' => 'change_quantity',
            'originatingLine' => $target,
            'quantity' => $newQuantity,
        ];

        return array_values($lineChanges);
    }

    /**
     * D3 (stage 4b-ii) — the unit price of the line the merchant is about to
     * add, painted next to the picker.
     *
     * COMPUTED AT RENDER TIME, from the dialog's own current state — see the
     * calling component's own note for why a state-bound TextEntry could not
     * be used for this.
     *
     * RESOLVED AT SELECTION TIME, FOR DISPLAY ONLY, AND RESOLVED AGAIN AT
     * SUBMIT TIME FOR THE WRITE ITSELF (editAction()'s own closure): the
     * number shown here may go stale if a price list changes while the
     * modal is open, and the write never trusts it — it re-prices through
     * the same OrderAddLinePricer::pricedChange(). Showing it is still worth
     * those reads, because §8's own posture for this dialog is "preview
     * before save, same code path as apply", and a merchant adding a line
     * blind is precisely what "never a free price override" leaves them
     * with otherwise.
     *
     * THE AMOUNT IS RENDERED THROUGH THE SAME MARKUP RULE EVERY OTHER
     * PRICE DISPLAY USES — ProductPriceDisplay::priceHtml(), which the
     * Lines table's own unit-price cell calls — so a discounted price is
     * struck through here exactly as it is there, and there is no second
     * price renderer. The whole line is returned as HtmlString (whose markup
     * the schemas' Text component passes through unescaped) with every
     * translated piece escaped here, at the one place the string is built.
     *
     * A REFUSAL IS SHOWN, NOT THROWN: a variation with no configured price
     * (or one that stopped being sellable between the picker's search and
     * this render) renders its own translated sentence in place of an
     * amount, so the merchant learns it before submitting rather than after
     * the same refusal arrives from the submit path.
     *
     * A PICK THE ORDER ALREADY SELLS SHOWS THE LINE'S OWN PRICE, NOT THE
     * PRICE LIST'S, because that is what the submission will actually use:
     * such a pick is written as a quantity change on that line (see
     * editAction()'s own merge note), and the units are counted at the price
     * the line already carries. Showing the live price there would be a
     * number the merchant never gets — precisely the sort of "preview that
     * does not match the write" this whole display exists to avoid — so the
     * amount is read off the resolved current line and a translated hint
     * says where the added units are going.
     */
    private static function addLinePricePreview(Get $get, OrderModel $record): HtmlString
    {
        $variationId = $get('add.variation_id');

        if (! is_string($variationId) && ! is_int($variationId)) {
            return new HtmlString('');
        }

        $variationId = trim((string) $variationId);

        if ($variationId === '') {
            return new HtmlString('');
        }

        // IS THIS A MERGE? Asked BEFORE the price is resolved, because a
        // merged pick is not priced from the price list at all, and asked
        // with the SAME rule the submission itself applies
        // (mergeTargetFor(): exactly one current line of that variation).
        $mergeLine = static::mergeTargetForOrder($record, $variationId);

        if ($mergeLine !== null) {
            $regularUnitPrice = $mergeLine->regularUnitPrice();
            $finalUnitPrice = $mergeLine->finalUnitPrice();

            // Unreachable for a line the resolver hands back — it refuses a
            // line with no §3.13 snapshot — but the accessors are nullable and
            // this file's own posture is a cheap corruption detector rather
            // than implicit trust: name the problem instead of feeding a null
            // to the markup rule.
            if ($regularUnitPrice === null || $finalUnitPrice === null) {
                return new HtmlString(e(__('orders.actions.edit_add_no_price')));
            }

            return new HtmlString(
                e(__('orders.fields.unit_price')).': '
                .app(ProductPriceDisplay::class)->priceHtml($regularUnitPrice, $finalUnitPrice)
                .'<br>'.e(__('orders.actions.edit_add_merge_hint'))
            );
        }

        $quantity = $get('add.quantity');
        $quantity = is_numeric($quantity) ? (int) $quantity : 0;

        try {
            $change = app(OrderAddLinePricer::class)->pricedChange(
                variationId: $variationId,
                quantity: max(1, $quantity),
                currency: $record->currency,
            );
        } catch (PriceNotConfiguredException) {
            return new HtmlString(e(__('orders.actions.edit_add_no_price')));
        } catch (OrderAddLineRefusedException) {
            return new HtmlString(e(__('orders.actions.edit_add_unavailable')));
        }

        $pricedLine = $change['pricedLine'];

        return new HtmlString(
            e(__('orders.fields.unit_price')).': '
            .app(ProductPriceDisplay::class)->priceHtml($pricedLine['regularUnitPrice'], $pricedLine['finalUnitPrice'])
        );
    }

    /**
     * D4 — the order's own always-on history (order-lifecycle-design.md
     * §6.3, §10 stage 7), THIS SECTION'S FIRST RENDERING: OrderAdminOrderView::
     * $events already existed with "NOTHING RENDERS IT YET" in its own
     * docblock (that class's own note) — this is the consumer.
     *
     * RENDERED THE SAME TABLE WAY Lines renders its own RepeatableEntry
     * (lineRows() above), not stacked/labelled-field-per-
     * event — one row per order_events row.
     *
     * NEWEST FIRST (§8.4's own words: "newest first, since a merchant opening
     * an order wants the last thing that happened"), AND THE REVERSAL HAPPENS
     * HERE RATHER THAN IN THE READ. OrderAdminReader::forOrder() documents —
     * and OrderAdminReaderEventsTest pins — that it returns events
     * oldest-first with the id breaking a same-second tie: that ordering is
     * the read's own identity, the one that lets any caller see the events in
     * the order they really happened in. Reversing it there would trade a
     * documented, tested read contract for ONE page's display preference, and
     * leave the tie-break untestable in the direction it is stated.
     * array_reverse() on the already-materialised list costs no query and
     * cannot disturb the tie-break: it reverses an order the read already
     * decided. Rows stay a LIST (0..n-1) afterwards — RepeatableEntry maps the
     * Nth cell to the Nth column, so keys must not be preserved.
     *
     * A FIXED COLUMN SET, UNLIKE Lines' OWN CONDITIONAL discretionary_discount
     * COLUMN — nothing here varies per record, so historyColumnSpecs() takes
     * no $record and neither ->table() nor ->schema() in infolist() needs a
     * per-record closure the way the former items table's callers did.
     *
     * SEVEN COLUMNS: Date (occurred_at), Event (type, via the SAME
     * event_type_options group the label-parity test already pins),
     * From/To (from_status/to_status, via the SAME status_options group the
     * list's own status column uses — both '—' for the four event types
     * that move no status, §6.1), Reason (the operator's own words,
     * verbatim, '—' when none was given), Return record (below) and By
     * (staff_name, or 'System' for a console/job caller —
     * OrderAdminEventView's own docblock already names this exact wording as
     * the View page's job).
     *
     * THE RETURN RECORD COLUMN REVERSES AN EARLIER DECISION MADE IN THIS VERY
     * DOCBLOCK, deliberately (§8.4, stage 7c-1). It used to read
     * "transaction_id is deliberately not a column — no admin surface reads or
     * links to a Transaction yet (no TransactionResource exists), so a raw id
     * would be noise nobody here can act on". The LINK half of §8.4's sentence
     * ("a link to the return's own lines where §6.1's transaction_id is set")
     * still cannot be honoured — there is still no Transaction page to link
     * to, and a link to nothing would be worse than text — so this renders the
     * id as PLAIN TEXT. What changed is the "noise nobody can act on" half: on
     * a returned row the id is the only thing that distinguishes one of §7.2's
     * several partial returns from another, and it is the reference the
     * merchant quotes when reconciling a return against the goods that came
     * back, which is a thing they act on daily. It shows '—' wherever
     * OrderAdminEventView::transactionId is NULL — every event type but the
     * return — which reads as "this event is not about a record elsewhere",
     * this page's own established word for an absent value (orders.not_available,
     * the same one Reason and the line columns use).
     *
     * @return array<int, array<string, string>>
     */
    private static function historyRows(OrderModel $record): array
    {
        $rows = array_map(
            fn (OrderAdminEventView $event): array => [
                'occurred_at' => $event->occurredAt->format('Y-m-d H:i'),
                'type' => static::optionLabel('event_type', $event->type),
                'from_status' => static::optionLabel('status', $event->fromStatus),
                'to_status' => static::optionLabel('status', $event->toStatus),
                'reason' => $event->reason ?? __('orders.not_available'),
                // THE KEY MUST BE THE COLUMN SPEC'S OWN KEY, NOT A
                // DESCRIPTIVE ONE: RepeatableEntry hands each row's array to
                // its child entries and each TextEntry resolves its state by
                // ITS OWN NAME (historyColumnSpecs()'s 'return_record'), so a
                // key named anything else is not a naming preference — the
                // cell renders empty. OrderViewPageTest pins the rendered
                // reference for exactly this reason.
                // The key's NAME is historical: the cell now serves edits as well as
                // returns and its label (orders.fields.return_record) is the authority.
                // Goods are listed only for event types that actually moved goods — a
                // payment_voided event can carry the same transaction id but moved
                // none, so it shows the record id alone. Decided on the TYPE, not on
                // whether the goods list is empty.
                'return_record' => $event->transactionId === null
                    ? __('orders.not_available')
                    : [
                        // The day the customer announced the return (refunds R3): a plain calendar day, no time.
                        ...($event->announcedReturnOn !== null ? [__('orders.history.announced_on', ['day' => $event->announcedReturnOn])] : []),
                        ...(in_array($event->type, ['returned', 'refunded', 'refund_owed', 'edited'], true)
                            ? array_map(static::movedLineLabel(...), $event->movedLines)
                            : []),
                        '#'.$event->transactionId,
                    ],
                'staff_name' => $event->staffName ?? __('orders.system_actor'),
            ],
            static::forOrder($record)->events,
        );

        // preserve_keys: false (the default) is the correct one — see this
        // method's own docblock.
        return array_reverse($rows);
    }

    /**
     * One line of the history's goods cell: `Alpha Widget (SKU-A) × 2`, or
     * `T-Shirt (TEE-1001 — Black, M) × 1` when the line's own snapshot has axis
     * values — the same `{name} ({sku}[ — axis values])` shape the order edit
     * picker uses, here from the stored snapshot (no extra read). An edit's
     * transaction holds BOTH what it took off and what it put on, so those are
     * marked `−` / `+`; a return's lines are the goods that came back and carry
     * no mark.
     *
     * @param  array{kind: string, name: ?string, sku: ?string, quantity: int, attributes: list<string>}  $line
     */
    private static function movedLineLabel(array $line): string
    {
        $inner = trim((string) $line['sku']);

        if ($line['attributes'] !== []) {
            $inner = $inner === '' ? implode(', ', $line['attributes']) : $inner.' — '.implode(', ', $line['attributes']);
        }

        $name = trim((string) $line['name']);
        $label = match (true) {
            $name === '' && $inner === '' => __('orders.not_available'),
            $name === '' => $inner,
            $inner === '' => $name,
            default => "{$name} ({$inner})",
        };

        $mark = match ($line['kind']) {
            'removed' => '− ',
            'added' => '+ ',
            default => '',
        };

        return "{$mark}{$label} × {$line['quantity']}";
    }

    /**
     * The payments that are not the order's current one, newest first, each
     * with a label for why it is no longer current — taken from facts the row
     * already holds, so it claims no more than they say: FAILED is a genuine
     * failed attempt, a set voidedAt is "superseded" (an edit or a return
     * changed what was owed; Payment::void() records the moment, not which of
     * the two it was — the order's own history, on this page, says that).
     *
     * @return array<int, array<string, string>>
     */
    private static function paymentHistoryRows(OrderModel $record): array
    {
        $view = static::forOrder($record);
        $currentId = $view->latestPayment?->id();

        $others = array_values(array_filter(
            $view->payments,
            static fn (Payment $payment): bool => $payment->id() !== $currentId,
        ));

        usort($others, static fn (Payment $a, Payment $b): int => [$b->attemptedAt()?->getTimestamp() ?? 0, (int) $b->id()]
            <=> [$a->attemptedAt()?->getTimestamp() ?? 0, (int) $a->id()]);

        return array_map(static function (Payment $payment): array {
            $money = $payment->amount();

            return [
                'amount' => app(PriceDisplayFormatter::class)->format($money->decimalValue(), $money->currency()),
                'method' => static::optionLabel('payment_method', $payment->method()),
                'status' => static::optionLabel('payment_status', $payment->status()->value),
                'attempted_at' => $payment->attemptedAt()?->format('Y-m-d H:i') ?? __('orders.not_available'),
                'why' => match (true) {
                    $payment->status() === PaymentStatus::FAILED => __('orders.payment_history.failed'),
                    $payment->voidedAt() !== null => __('orders.payment_history.superseded'),
                    default => __('orders.not_available'),
                },
            ];
        }, $others);
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
            ['key' => 'return_record', 'label' => __('orders.fields.return_record')],
            ['key' => 'staff_name', 'label' => __('orders.fields.staff_name')],
        ];
    }
}
