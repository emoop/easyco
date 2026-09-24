<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Services\OrderAdminReader;
use App\Services\PriceDisplayFormatter;
use BackedEnum;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\Money;
use EasyCo\Staff\Enums\Permission;
use Filament\Resources\Resource;
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
        ];
    }
}
