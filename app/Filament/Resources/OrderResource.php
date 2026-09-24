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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
     * D3's own field's own real accepted shape, per column:
     *  - id: order number (there is no separate human-readable one —
     *    §0's own note), searchable, default part of the sort tie-break.
     *  - placed_at: default sort, descending.
     *  - recipient_name (+ email as a description line): searchable
     *    against email specifically, per the task's own "Search: order
     *    id and email" — recipient_name itself is never matched, only
     *    displayed.
     *  - client_name/channel/payment_method/payment_status/item_count:
     *    OrderAdminReader::applyListAggregates()'s own computed columns,
     *    plain display, not independently sortable (each is a
     *    correlated subquery result, not a real indexed column — kept
     *    simple rather than fighting Filament's sort-by-alias support
     *    for columns nothing here requires sorting on).
     *  - total: formatted through the same PriceDisplayFormatter every
     *    other price display in this admin panel already uses.
     *
     * NO FILTERS — explicit task scope.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => app(OrderAdminReader::class)->applyListAggregates($query))
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
                TextColumn::make('client_name')
                    ->label(__('orders.fields.client_name'))
                    ->formatStateUsing(fn (?string $state): string => $state ?? __('orders.not_available')),
                TextColumn::make('channel')
                    ->label(__('orders.fields.channel'))
                    ->formatStateUsing(fn (?string $state): string => $state !== null
                        ? __("orders.channel_options.{$state}")
                        : __('orders.not_available')),
                TextColumn::make('payment_method')
                    ->label(__('orders.fields.payment_method'))
                    ->formatStateUsing(fn (?string $state): string => $state !== null
                        ? __("orders.payment_method_options.{$state}")
                        : __('orders.not_available')),
                TextColumn::make('payment_status')
                    ->label(__('orders.fields.payment_status'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'captured' => 'success',
                        'pending' => 'gray',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => $state !== null
                        ? __("orders.payment_status_options.{$state}")
                        : __('orders.not_available')),
                TextColumn::make('item_count')
                    ->label(__('orders.fields.item_count')),
                TextColumn::make('total')
                    ->label(__('orders.fields.total'))
                    ->getStateUsing(fn (OrderModel $record): string => app(PriceDisplayFormatter::class)->format(
                        Money::fromMinorUnits((int) $record->total_minor, $record->currency)->decimalValue(),
                        Currency::of($record->currency)
                    )),
            ])
            ->filters([]);
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
                        ->getStateUsing(function (OrderModel $record): string {
                            $channel = static::forOrder($record)->channel;

                            return array_key_exists($channel, ['web' => true, 'pos' => true])
                                ? __("orders.channel_options.{$channel}")
                                : $channel;
                        }),
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
                        ->table([
                            RepeatableTableColumn::make(__('orders.fields.product_name')),
                            RepeatableTableColumn::make(__('orders.fields.sku')),
                            RepeatableTableColumn::make(__('orders.fields.quantity')),
                            RepeatableTableColumn::make(__('orders.fields.unit_price')),
                            RepeatableTableColumn::make(__('orders.fields.line_total')),
                        ])
                        ->schema([
                            TextEntry::make('product_name')->hiddenLabel(),
                            TextEntry::make('sku')->hiddenLabel(),
                            TextEntry::make('quantity')->hiddenLabel(),
                            TextEntry::make('unit_price')->hiddenLabel(),
                            TextEntry::make('line_total')->hiddenLabel(),
                        ]),
                ]),
            Section::make(__('orders.sections.promotion'))
                ->schema([
                    TextEntry::make('applied_promotion_code')
                        ->label(__('orders.fields.promotion_code'))
                        ->formatStateUsing(fn (?string $state): string => $state ?? __('orders.no_promotion')),
                    TextEntry::make('discount_minor')
                        ->label(__('orders.fields.discount'))
                        ->visible(fn (OrderModel $record): bool => $record->applied_promotion_code !== null)
                        ->getStateUsing(fn (OrderModel $record): string => app(PriceDisplayFormatter::class)->format(
                            Money::fromMinorUnits((int) $record->discount_minor, $record->currency)->decimalValue(),
                            Currency::of($record->currency)
                        )),
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
                        ->getStateUsing(fn (OrderModel $record): string => app(PriceDisplayFormatter::class)->format(
                            Money::fromMinorUnits((int) $record->subtotal_minor, $record->currency)->decimalValue(),
                            Currency::of($record->currency)
                        )),
                    TextEntry::make('discount_minor_total')
                        ->label(__('orders.fields.discount'))
                        ->getStateUsing(fn (OrderModel $record): string => app(PriceDisplayFormatter::class)->format(
                            Money::fromMinorUnits((int) $record->discount_minor, $record->currency)->decimalValue(),
                            Currency::of($record->currency)
                        )),
                    TextEntry::make('total_minor')
                        ->label(__('orders.fields.total'))
                        ->getStateUsing(fn (OrderModel $record): string => app(PriceDisplayFormatter::class)->format(
                            Money::fromMinorUnits((int) $record->total_minor, $record->currency)->decimalValue(),
                            Currency::of($record->currency)
                        )),
                ])
                ->columns(3),
            Section::make(__('orders.sections.payment'))
                ->schema([
                    TextEntry::make('payment_method')
                        ->label(__('orders.fields.payment_method'))
                        ->getStateUsing(function (OrderModel $record): string {
                            $payment = static::forOrder($record)->latestPayment;

                            if ($payment === null) {
                                return __('orders.no_payment');
                            }

                            return __("orders.payment_method_options.{$payment->method()}");
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
                                ? __("orders.payment_status_options.{$payment->status()->value}")
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

    /** @return array<int, array{product_name: string, sku: string, quantity: int, unit_price: string, line_total: string}> */
    private static function lineRows(OrderModel $record): array
    {
        $formatter = app(PriceDisplayFormatter::class);

        return array_map(
            fn (OrderAdminSaleLineView $line): array => [
                'product_name' => $line->productName ?? __('orders.not_available'),
                'sku' => $line->sku ?? __('orders.not_available'),
                'quantity' => $line->quantity,
                'unit_price' => $line->unitPrice !== null
                    ? $formatter->format($line->unitPrice->decimalValue(), $line->unitPrice->currency())
                    : __('orders.not_available'),
                'line_total' => $formatter->format($line->lineTotal->decimalValue(), $line->lineTotal->currency()),
            ],
            static::forOrder($record)->lines
        );
    }
}
