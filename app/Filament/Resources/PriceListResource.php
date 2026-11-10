<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\Design\InfoHint;
use App\Filament\NavigationGroup;
use App\Filament\Resources\PriceListResource\Pages\CreatePriceList;
use App\Filament\Resources\PriceListResource\Pages\EditPriceList;
use App\Filament\Resources\PriceListResource\Pages\ListPriceLists;
use App\Filament\Support\HelpLink;
use App\Services\CatalogScopeLookup;
use App\Services\Exceptions\PriceListInvalidException;
use App\Services\PriceListAdminReader;
use App\Services\PriceListWriter;
use App\Services\PriceSort\SortPriceRefreshDispatcher;
use App\Services\PromotionWriter;
use App\Settings\StoreTimezone;
use BackedEnum;
use DateTimeImmutable;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Enums\PriceListScopeType;
use EasyCo\Pricing\Persistence\Eloquent\PriceListModel;
use EasyCo\Pricing\Persistence\Eloquent\PriceListScopeModel;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use WeakMap;

/**
 * P6b: «Ценови листове» under the «Маркетинг» group, after «Промокодове»: the list, the create / edit form and «Дублирай» for percent-off CAMPAIGNS (name, percent, optional start and end, the active switch, the scopes
 * and the «Замества ръчната промо цена» switch). The permission is `price_manage` for everything.
 *
 * EVERY write is one call to PriceListWriter (the domain entity, the repositories, the scopes, the audit, the refresh of the stored sort price, the hooks): a Filament page never writes a price-list row. A price list is
 * NEVER deleted: it is switched on or off. The two SYSTEM lists («Regular Prices», «Manual Sale») are listed as «Системен» and are read-only: no actions, and a direct URL to their edit page is a 403 (canEdit()).
 *
 * THE LIST READS IN A BOUNDED NUMBER OF QUERIES whatever the number of lists: the page of lists once, and the scope summaries of ALL listed lists through PriceListAdminReader (one read of the scope rows and at most one
 * name read per kind), computed once per request.
 */
class PriceListResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = PriceListModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-receipt-percent';

    /** Per request: the listed records => list id => kind => names. */
    private static ?WeakMap $listScopes = null;

    public static function getModelLabel(): string
    {
        return __('price_lists.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('price_lists.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('price_lists.navigation_label');
    }

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::MARKETING;
    }

    /** After «Промокодове» (10). */
    public static function getNavigationSort(): ?int
    {
        return 20;
    }

    protected static function viewAnyPermission(): ?Permission
    {
        return Permission::PRICE_MANAGE;
    }

    protected static function viewPermission(): ?Permission
    {
        return Permission::PRICE_MANAGE;
    }

    protected static function createPermission(): ?Permission
    {
        return Permission::PRICE_MANAGE;
    }

    protected static function editPermission(): ?Permission
    {
        return Permission::PRICE_MANAGE;
    }

    /** A price list is never deleted. */
    protected static function deletePermission(): ?Permission
    {
        return null;
    }

    /** Only a merchant's percent-off campaign can be edited: never a system list (and never a fixed-items list). */
    public static function canEdit(Model $record): bool
    {
        return $record instanceof PriceListModel && static::isCampaign($record) && parent::canEdit($record);
    }

    public static function isCampaign(PriceListModel $list): bool
    {
        return ! $list->is_system && $list->mode === PriceListMode::PERCENTAGE_OFF_REGULAR->value;
    }

    /** Active first, then the newest. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->orderByRaw("status = 'active' DESC")->orderByDesc('id');
    }

    // ---- the form ------------------------------------------------------------------------------------------

    public static function form(Schema $schema): Schema
    {
        $kinds = [];

        foreach (CatalogScopeLookup::KINDS as $kind) {
            $kinds[] = Select::make('scope_'.$kind->value)
                ->label(__('price_lists.fields.scope_'.$kind->value))
                ->multiple()
                ->searchable()
                ->preload()
                ->live()
                ->options(fn (): array => app(CatalogScopeLookup::class)->search($kind, ''))
                ->getSearchResultsUsing(fn (string $search): array => app(CatalogScopeLookup::class)->search($kind, $search))
                ->getOptionLabelsUsing(fn (array $values): array => collect($values)->mapWithKeys(fn ($id): array => [(string) $id => (string) app(CatalogScopeLookup::class)->label($kind, (string) $id)])->all());
        }

        return $schema
            ->extraAttributes(['novalidate' => true], merge: true)
            ->columns(1)
            ->components([
                Section::make(__('price_lists.sections.campaign'))->compact()->columns(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('name')
                        ->label(__('price_lists.fields.name'))
                        ->afterLabel(InfoHint::make(__('price_lists.hints.name'), 'name'))
                        ->maxLength(255)
                        ->columnSpanFull(),
                    TextInput::make('percentage')
                        ->label(__('price_lists.fields.percentage'))
                        ->afterLabel(InfoHint::make(__('price_lists.hints.percentage'), 'percentage'))
                        ->inputMode('decimal')
                        ->maxLength(6),
                    Toggle::make('is_active')
                        ->label(__('price_lists.fields.is_active'))
                        ->afterLabel(InfoHint::make(__('price_lists.hints.is_active'), 'is_active'))
                        ->default(true),
                    DateTimePicker::make('valid_from')
                        ->label(__('price_lists.fields.valid_from'))
                        ->afterLabel(InfoHint::make(__('price_lists.hints.valid_from'), 'valid_from'))
                        ->seconds(false),
                    DateTimePicker::make('valid_until')
                        ->label(__('price_lists.fields.valid_until'))
                        ->afterLabel(InfoHint::make(__('price_lists.hints.valid_until'), 'valid_until'))
                        ->seconds(false),
                    Toggle::make('replaces_manual_sale')
                        ->label(__('price_lists.fields.replaces_manual_sale'))
                        ->afterLabel(InfoHint::make(__('price_lists.hints.replaces_manual_sale'), 'replaces_manual_sale'))
                        ->columnSpanFull(),
                ]),

                Section::make(__('price_lists.sections.scope'))->compact()->columns(['default' => 1, 'md' => 2])->schema([
                    // the explanation of the AND / OR rule: one icon, not a paragraph
                    View::make('filament.price-lists.scope-hint')
                        ->viewData(['hint' => InfoHint::make(__('price_lists.hints.scopes'), 'scopes')])
                        ->columnSpanFull(),

                    ...$kinds,

                    // the dangerous case: NO scope = ALL products. Always visible text, and the save needs the explicit confirmation
                    View::make('filament.price-lists.all-products-warning')
                        ->visible(fn (Get $get): bool => ! static::hasAnyScope($get))
                        ->columnSpanFull(),
                    Toggle::make('confirm_all_products')
                        ->label(__('price_lists.fields.confirm_all_products'))
                        ->visible(fn (Get $get): bool => ! static::hasAnyScope($get))
                        ->live()
                        ->columnSpanFull(),

                    // the edit page: how many products the SAVED scope touches (read-only; computed when the form is filled, never submitted)
                    Hidden::make('affects')->dehydrated(false),
                    View::make('filament.price-lists.affects')
                        ->viewData(fn (Get $get): array => ['affects' => $get('affects')])
                        ->visible(fn (Get $get): bool => $get('affects') !== null && $get('affects') !== '')
                        ->columnSpanFull(),
                ]),

                HelpLink::group(['price_list_overview', 'price_list_scope', 'price_list_manual_sale'], 'price_lists'),
            ]);
    }

    private static function hasAnyScope(Get $get): bool
    {
        foreach (CatalogScopeLookup::KINDS as $kind) {
            $value = $get('scope_'.$kind->value);

            if (is_array($value) && $value !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The form's state for a stored campaign (the edit page, and «Дублирай» with a copy name, switched OFF).
     *
     * @return array<string, mixed>
     */
    public static function stateFor(PriceListModel $model, bool $duplicate = false): array
    {
        $zone = app(StoreTimezone::class)->zone();
        $local = static fn ($at): ?string => $at === null ? null : $at->toDateTimeImmutable()->setTimezone($zone)->format('Y-m-d H:i:s');

        $state = [
            'name' => $duplicate ? __('price_lists.copy_of', ['name' => $model->name]) : $model->name,
            'percentage' => $model->percentage_basis_points !== null ? PromotionWriter::percentText((int) $model->percentage_basis_points) : null,
            'valid_from' => $local($model->valid_from),
            'valid_until' => $local($model->valid_until),
            'is_active' => $duplicate ? false : $model->status === 'active',
            'replaces_manual_sale' => (bool) $model->replaces_manual_sale,
            'confirm_all_products' => false,
        ];

        foreach (CatalogScopeLookup::KINDS as $kind) {
            $state['scope_'.$kind->value] = [];
        }

        foreach (PriceListScopeModel::query()->where('price_list_id', $model->id)->orderBy('id')->get() as $scope) {
            $state['scope_'.$scope->scope_type][] = (string) $scope->scope_reference_id;
        }

        $state['affects'] = $duplicate ? null : static::affectsText((string) $model->id);

        return $state;
    }

    /** «Засяга N продукта» / «Засяга всички продукти» for a stored list (null = nothing to show). */
    public static function affectsText(string $listId): string
    {
        $ids = app(SortPriceRefreshDispatcher::class)->productsOfList($listId);

        return $ids === null ? __('price_lists.affects.all') : trans_choice('price_lists.affects.count', count($ids), ['count' => count($ids)]);
    }

    /**
     * The scope rows of the form's state as the writer wants them: [['type' => kind, 'reference' => id], …].
     *
     * @param  array<string, mixed>  $data
     * @return list<array{type: string, reference: string}>
     */
    public static function scopesFrom(array $data): array
    {
        $out = [];

        foreach (CatalogScopeLookup::KINDS as $kind) {
            foreach ((array) ($data['scope_'.$kind->value] ?? []) as $reference) {
                $out[] = ['type' => $kind->value, 'reference' => (string) $reference];
            }
        }

        return $out;
    }

    // ---- the list ------------------------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading(__('price_lists.table.empty'))
            ->emptyStateDescription(__('price_lists.table.empty_description'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('price_lists.table.name'))
                    ->weight('bold')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('discount')
                    ->label(__('price_lists.table.discount'))
                    ->state(fn (PriceListModel $record): string => static::discountText($record)),
                TextColumn::make('period')
                    ->label(__('price_lists.table.period'))
                    ->state(fn (PriceListModel $record): string => static::periodText($record))
                    ->wrap(),
                TextColumn::make('scope')
                    ->label(__('price_lists.table.scope'))
                    ->state(fn (PriceListModel $record, $livewire): string => static::scopeText($record, static::scopeNames($livewire)))
                    ->wrap(),
                TextColumn::make('replaces')
                    ->label(__('price_lists.table.replaces'))
                    ->badge()
                    ->color('info')
                    ->state(fn (PriceListModel $record): ?string => $record->replaces_manual_sale ? __('price_lists.table.replaces') : null),
                TextColumn::make('state')
                    ->label(__('price_lists.table.status'))
                    ->badge()
                    ->state(fn (PriceListModel $record): string => static::statusOf($record))
                    ->formatStateUsing(fn (string $state): string => __('price_lists.table.'.($state === 'system' ? 'system' : 'status_'.$state)))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'planned' => 'warning',
                        'expired' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label(__('price_lists.table.filter_status'))
                    ->options(array_combine(['active', 'planned', 'expired', 'inactive'], array_map(fn (string $s): string => __('price_lists.table.status_'.$s), ['active', 'planned', 'expired', 'inactive'])))
                    ->query(function (Builder $query, array $data): Builder {
                        $now = now();

                        return match ($data['value'] ?? null) {
                            'inactive' => $query->where('status', 'inactive'),
                            'planned' => $query->where('status', 'active')->where('valid_from', '>', $now),
                            'expired' => $query->where('status', 'active')->where('valid_until', '<', $now),
                            'active' => $query->where('status', 'active')->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now)),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('edit_price_list')
                        ->label(__('price_lists.actions.edit'))
                        ->icon('heroicon-o-pencil-square')
                        ->url(fn (PriceListModel $record): string => static::getUrl('edit', ['record' => $record]))
                        ->visible(fn (PriceListModel $record): bool => static::canEdit($record)),
                    Action::make('duplicate_price_list')
                        ->label(__('price_lists.actions.duplicate'))
                        ->icon('heroicon-o-document-duplicate')
                        ->url(fn (PriceListModel $record): string => static::getUrl('create', ['duplicate' => $record->id]))
                        ->visible(fn (PriceListModel $record): bool => static::isCampaign($record) && static::canCreate()),
                    static::statusAction(),
                ])
                    ->label(__('price_lists.actions.menu'))
                    ->icon('heroicon-m-chevron-down')
                    ->iconPosition(IconPosition::After)
                    ->color('gray')
                    ->button()
                    ->visible(fn (PriceListModel $record): bool => static::isCampaign($record)),
            ]);
    }

    /** Switches a campaign on or off — through PriceListWriter (audit, refresh, hooks), the one place that writes. */
    private static function statusAction(): Action
    {
        return Action::make('toggle_price_list')
            ->label(fn (PriceListModel $record): string => $record->status === 'active' ? __('price_lists.actions.deactivate') : __('price_lists.actions.activate'))
            ->icon(fn (PriceListModel $record): string => $record->status === 'active' ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
            ->visible(fn (PriceListModel $record): bool => static::canEdit($record))
            ->action(function (PriceListModel $record): void {
                $turnOn = $record->status !== 'active';

                try {
                    app(PriceListWriter::class)->setActive((string) $record->id, $turnOn);
                } catch (PriceListInvalidException $exception) {
                    Notification::make()->title(__('price_lists.errors.invalid'))->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title($turnOn ? __('price_lists.notice.activated') : __('price_lists.notice.deactivated'))->success()->send();
            });
    }

    /**
     * The scope names of the lists the table shows — read ONCE per request (PriceListAdminReader).
     *
     * @return array<string, array<string, list<string>>>
     */
    private static function scopeNames($livewire): array
    {
        $records = $livewire->getTableRecords();

        static::$listScopes ??= new WeakMap();

        return static::$listScopes[$records] ??= app(PriceListAdminReader::class)->scopeNames($records->pluck('id')->all());
    }

    // ---- what the list prints (pure, tested) ------------------------------------------------------------------

    /** «system», or «inactive» / «planned» / «active» / «expired» of a campaign, from the status and the dates (UTC now). */
    public static function statusOf(PriceListModel $list, ?DateTimeImmutable $now = null): string
    {
        if ($list->is_system) {
            return 'system';
        }

        if ($list->status !== 'active') {
            return 'inactive';
        }

        $now ??= new DateTimeImmutable();

        if ($list->valid_from !== null && $list->valid_from->toDateTimeImmutable() > $now) {
            return 'planned';
        }

        if ($list->valid_until !== null && $list->valid_until->toDateTimeImmutable() < $now) {
            return 'expired';
        }

        return 'active';
    }

    public static function discountText(PriceListModel $list): string
    {
        if ($list->percentage_basis_points === null) {
            return '—';
        }

        $text = PromotionWriter::percentText((int) $list->percentage_basis_points);

        return (str_starts_with(app()->getLocale(), 'bg') ? str_replace('.', ',', $text) : $text).'%';
    }

    public static function periodText(PriceListModel $list): string
    {
        $zone = app(StoreTimezone::class)->zone();
        $format = static fn ($at): string => $at->toDateTimeImmutable()->setTimezone($zone)->format('d.m.Y H:i');

        return match (true) {
            $list->valid_from !== null && $list->valid_until !== null => __('price_lists.table.from_until', ['from' => $format($list->valid_from), 'until' => $format($list->valid_until)]),
            $list->valid_from !== null => __('price_lists.table.from_only', ['from' => $format($list->valid_from)]),
            $list->valid_until !== null => __('price_lists.table.until_only', ['until' => $format($list->valid_until)]),
            default => __('price_lists.table.no_limit'),
        };
    }

    /**
     * «Марка: Acme · Сезон: Есен-26» (several of one kind: «Таг: A, B»); «Всички продукти» without a scope.
     *
     * @param  array<string, array<string, list<string>>>  $namesByList
     */
    public static function scopeText(PriceListModel $list, array $namesByList): string
    {
        $kinds = $namesByList[(string) $list->id] ?? [];

        if ($kinds === []) {
            return __('price_lists.table.all_products');
        }

        $parts = [];

        foreach (CatalogScopeLookup::KINDS as $kind) {
            if (isset($kinds[$kind->value])) {
                $parts[] = __('price_lists.table.scope_kind.'.$kind->value).': '.implode(', ', $kinds[$kind->value]);
            }
        }

        return implode(' · ', $parts);
    }

    // ---- shared by the pages -------------------------------------------------------------------------------

    /**
     * A writer refusal as the form's own field errors (the form's state lives under `data`). A refusal about the scope lands on the scope pickers' first field.
     *
     * @throws ValidationException
     */
    public static function fieldErrors(PriceListInvalidException $exception): never
    {
        $messages = [];

        foreach ($exception->errors as $field => $list) {
            $messages['data.'.($field === 'scopes' ? 'scope_'.PriceListScopeType::BRAND->value : $field)] = $list;
        }

        throw ValidationException::withMessages($messages);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPriceLists::route('/'),
            'create' => CreatePriceList::route('/create'),
            'edit' => EditPriceList::route('/{record}/edit'),
        ];
    }
}
