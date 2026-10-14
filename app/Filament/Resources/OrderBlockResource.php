<?php

namespace App\Filament\Resources;

use App\Enums\OrderBlockType;
use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\OrderBlockResource\Pages\ListOrderBlocks;
use App\Filament\Support\HelpLink;
use App\Filament\Support\OrderBlockActions;
use App\Models\OrderBlockModel;
use App\Services\OrderBlockMasker;
use App\Services\OrderBlockService;
use App\Services\OrderContextReader;
use BackedEnum;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * «Блокирания» (cod-protection-design.md 3.3, stage C2-B): every cash-on-delivery block, in the Orders group. Read by anyone with ORDER_VIEW with
 * the values MASKED (a phone as `*** *** 456`, an email as `m***@gmail.com`, an IP as `203.0.*.*`); the FULL IP only for ORDER_MANAGE, who alone may
 * also unblock, extend and add a block by hand (an offender the owner already knows). Filters: the type and the state (active / expired /
 * revoked). Every write is one call to OrderBlockService (which writes the activity-log row with the masked value). No create or edit page, no
 * delete: a block ends by being revoked, then ages out after 30 days (`order-blocks:prune`).
 *
 * ONE QUERY for the page of blocks (the creator's name is joined, never read per row).
 */
class OrderBlockResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = OrderBlockModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-no-symbol';

    public static function getModelLabel(): string
    {
        return __('blocks.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('blocks.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('blocks.navigation_label');
    }

    /** In the Orders group, right after the orders list (10). */
    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::SALES;
    }

    public static function getNavigationSort(): ?int
    {
        return 15;
    }

    protected static function viewAnyPermission(): ?Permission
    {
        return Permission::ORDER_VIEW;
    }

    public static function getEloquentQuery(): Builder
    {
        return OrderBlockModel::query()
            ->leftJoin('staff', 'staff.id', '=', 'order_blocks.created_by')
            ->select('order_blocks.*', 'staff.name as created_by_name');
    }

    public static function table(Table $table): Table
    {
        $now = static fn () => Date::now();

        return $table
            ->defaultSort('order_blocks.id', 'desc')
            ->columns([
                TextColumn::make('type')
                    ->label(__('blocks.columns.type'))
                    ->formatStateUsing(fn (string $state): string => __('blocks.type_options.'.$state)),
                TextColumn::make('value_display')
                    ->label(__('blocks.columns.value'))
                    ->formatStateUsing(fn (string $state, OrderBlockModel $record): string => OrderBlockMasker::mask(OrderBlockType::from($record->type), $state, OrderBlockActions::mayManage())),
                TextColumn::make('reason')
                    ->label(__('blocks.columns.reason'))
                    ->placeholder('—')
                    ->limit(60),
                TextColumn::make('source_order_id')
                    ->label(__('blocks.columns.order'))
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state): string => '#'.$state)
                    ->url(fn (OrderBlockModel $record): ?string => $record->source_order_id === null ? null : OrderResource::getUrl('view', ['record' => $record->source_order_id])),
                TextColumn::make('created_by_name')
                    ->label(__('blocks.columns.by'))
                    ->placeholder(__('blocks.unknown_staff')),
                TextColumn::make('expires_at')
                    ->label(__('blocks.columns.until'))
                    ->dateTime('M j, Y')
                    ->placeholder(__('blocks.permanent')),
                TextColumn::make('status')
                    ->label(__('blocks.columns.status'))
                    ->badge()
                    ->getStateUsing(fn (OrderBlockModel $record): string => $record->state($now()))
                    ->formatStateUsing(fn (string $state): string => __('blocks.status.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'danger',
                        'expired' => 'gray',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('blocks.filters.type'))
                    ->options(array_combine(array_map(fn (OrderBlockType $t): string => $t->value, OrderBlockType::cases()), array_map(fn (OrderBlockType $t): string => __('blocks.type_options.'.$t->value), OrderBlockType::cases())))
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('order_blocks.type', $data['value']) : $query),
                SelectFilter::make('state')
                    ->label(__('blocks.filters.status'))
                    ->options(['active' => __('blocks.status.active'), 'expired' => __('blocks.status.expired'), 'revoked' => __('blocks.status.revoked')])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'active' => $query->active(Date::now()),
                            'revoked' => $query->whereNotNull('order_blocks.revoked_at'),
                            'expired' => $query->whereNull('order_blocks.revoked_at')->whereNotNull('order_blocks.expires_at')->where('order_blocks.expires_at', '<=', Date::now()),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                Action::make('extend')
                    ->label(__('blocks.actions.extend'))
                    ->icon('heroicon-m-clock')
                    ->color('gray')
                    ->modalHeading(__('blocks.actions.extend_heading'))
                    ->schema([...array_slice(OrderBlockActions::durationFields(), 0, 2), HelpLink::component('block_identifier')])
                    ->visible(fn (OrderBlockModel $record): bool => OrderBlockActions::mayManage() && $record->isActive(Date::now()))
                    ->action(function (OrderBlockModel $record, array $data): void {
                        app(OrderBlockService::class)->extend($record, OrderBlockActions::daysFrom($data));
                        Notification::make()->title(__('blocks.notice.extended'))->success()->send();
                    }),
                Action::make('revoke')
                    ->label(__('blocks.actions.unblock'))
                    ->icon('heroicon-m-lock-open')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (OrderBlockModel $record): string => __('blocks.actions.unblock_heading', ['type' => mb_strtolower(__('blocks.type_options.'.$record->type))]))
                    ->modalDescription(fn (OrderBlockModel $record): string => __('blocks.actions.unblock_description', ['type' => mb_strtolower(__('blocks.type_options.'.$record->type))]))
                    ->modalContent(HelpLink::html('block_identifier'))
                    ->visible(fn (OrderBlockModel $record): bool => OrderBlockActions::mayManage() && $record->isActive(Date::now()))
                    ->action(function (OrderBlockModel $record): void {
                        app(OrderBlockService::class)->revoke($record);
                        Notification::make()->title(__('blocks.notice.unblocked'))->success()->send();
                    }),
            ])
            ->paginated([25, 50]);
    }

    /** The header «Добави блокиране»: a block made by hand, without an order (ORDER_MANAGE). */
    public static function addAction(): Action
    {
        return Action::make('add_block')
            ->label(__('blocks.actions.add'))
            ->icon('heroicon-m-plus')
            ->modalHeading(__('blocks.actions.add_heading'))
            ->modalDescription(__('blocks.form.note'))
            ->schema([
                \Filament\Forms\Components\Select::make('type')
                    ->label(__('blocks.form.type'))
                    ->options(array_combine(array_map(fn (OrderBlockType $t): string => $t->value, OrderBlockType::cases()), array_map(fn (OrderBlockType $t): string => __('blocks.type_options.'.$t->value), OrderBlockType::cases())))
                    ->required(),
                \Filament\Forms\Components\TextInput::make('value')
                    ->label(__('blocks.form.value'))
                    ->required()
                    ->maxLength(255)
                    ->rules([new \App\Rules\PlainText()]),
                ...OrderBlockActions::durationFields(),
                HelpLink::component('block_identifier'),
            ])
            ->visible(fn (): bool => OrderBlockActions::mayManage())
            ->action(function (array $data): void {
                try {
                    app(OrderBlockService::class)->block(OrderBlockType::from($data['type']), (string) $data['value'], OrderBlockActions::daysFrom($data), $data['reason'] ?? null);
                } catch (\InvalidArgumentException) {
                    Notification::make()->title(__('blocks.notice.cannot_block'))->danger()->send();

                    return;
                }

                Notification::make()->title(__('blocks.notice.blocked'))->success()->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListOrderBlocks::route('/')];
    }
}
