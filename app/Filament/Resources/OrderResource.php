<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Services\OrderAdminOrderView;
use App\Services\OrderAdminReader;
use App\Services\OrderAdminSaleLineView;
use App\Services\PriceDisplayFormatter;
use App\Services\ProductPriceDisplay;
use BackedEnum;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\Money;
use EasyCo\Staff\Enums\Permission;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as RepeatableTableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Lang;

/**
 * Orders — admin-panel-design.md §14. STRICTLY READ-ONLY (D1): list and
 * view only, no create/edit/delete pages, no bulk actions, no status
 * transitions. createPermission()/editPermission()/deletePermission()
 * are deliberately left unset — AuthorizesViaStaffPermission's own
 * staffCanForAction() fails closed for an undeclared permission (see
 * that trait's own docblock), so canCreate()/canEdit()/canDelete() are
 * false for every role without a single line of code here; asserted by
 * a real test, not just implied by omission.
 *
 * Gated by Permission::ORDER_VIEW alone — Administrator and Manager
 * hold it, Product Entry does not (StaffSystemRolesSeeder).
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
     * D2 — ONE QUICK FILTER, RENDERED ABOVE THE TABLE
     * (FiltersLayout::AboveContent, so it is visible without opening a
     * filter dropdown): Payment method, single-select and clearable. A
     * Channel filter was deliberately NOT built — the `orders` table only
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
            // No ->modifyQueryUsing() any more: all five columns above are
            // real `orders` columns, so the list no longer needs any of the
            // correlated subqueries the removed aggregate columns carried.
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
            ], layout: FiltersLayout::AboveContent);
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
                            static fn (array $spec): RepeatableTableColumn => RepeatableTableColumn::make($spec['label']),
                            static::lineColumnSpecs($record),
                        ))
                        ->schema(fn (OrderModel $record): array => array_map(
                            static fn (array $spec): TextEntry => static::lineCell($spec['key']),
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
                ->columns(3),
        ]);
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
     * The four numbers a merchant reads off a line are NAMED on the line
     * itself as well as in the header row, by lineCell() — see its own
     * docblock; the values here stay pure values.
     *
     * @return array<int, array<string, string>>
     */
    private static function lineRows(OrderModel $record): array
    {
        return array_map(
            fn (OrderAdminSaleLineView $line): array => [
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
     * @return array<int, array{key: string, label: string}>
     */
    private static function lineColumnSpecs(OrderModel $record): array
    {
        $specs = [
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
     * merchant reads off a line each get their own name in front of them
     * (Бройка 1, Цена 48.00 €, Отстъпка 0.00 €, Сума 48.00 €) rather than
     * relying only on the header row, which scrolls out of sight on a table
     * this wide. lineRows() keeps returning pure values; naming a value is
     * a presentation decision of its cell.
     */
    private static function lineCell(string $key): TextEntry
    {
        $entry = TextEntry::make($key)->hiddenLabel();

        if ($key === 'product_name' || $key === 'unit_price') {
            $entry->html();
        }

        if (($label = static::lineValueLabel($key)) !== null) {
            // The trailing space is INSIDE the prefix on purpose:
            // Filament's own formatState() concatenates a prefix onto the
            // state with no separator of its own, so 'Бройка' alone would
            // render 'Бройка1'.
            $entry->prefix($label.' ');
        }

        return $entry;
    }

    /**
     * The name a line puts in front of one of its own values, in the words
     * the merchant uses for them (admin-panel-design.md §14) — or null for
     * the values a line does NOT name:
     *
     *  - product_name / sku are identified by their own content (the product
     *    name, the SKU), not by a label;
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
}
