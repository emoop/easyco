<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\Design\InfoHint;
use App\Filament\NavigationGroup;
use App\Filament\Support\HelpLink;
use App\Filament\Resources\PromotionResource\Pages\CreatePromotion;
use App\Filament\Resources\PromotionResource\Pages\EditPromotion;
use App\Filament\Resources\PromotionResource\Pages\ListPromotions;
use App\Services\Exceptions\PromotionInvalidException;
use App\Services\MoneyInput;
use App\Services\PriceDisplayFormatter;
use App\Services\PromotionAdminReader;
use App\Services\PromotionWriter;
use BackedEnum;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\Money;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Enums\PromotionScopeMode;
use EasyCo\Promotions\Enums\PromotionScopeType;
use EasyCo\Promotions\Persistence\Eloquent\PromotionModel;
use EasyCo\Promotions\Persistence\Eloquent\PromotionScopeModel;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use WeakMap;

/**
 * P5b: «Промокодове» under the «Маркетинг» group — the list, the create / edit form and «Дублирай». The permission is `promotion_manage` for everything.
 *
 * EVERY write is one call to PromotionWriter (the domain entity, the repositories, the scopes in one transaction): a Filament page never writes a promotion row. A promotion is NEVER deleted (it has redemption history):
 * there is a status switch («Активен») and no delete action anywhere. The code, the discount type and the discount value are shown read-only on the edit page and never read from its data.
 *
 * THE LIST READS IN A BOUNDED NUMBER OF QUERIES whatever the number of promotions: the page of promotions once, and the used count and the scope count of ALL listed promotions in two grouped reads
 * (PromotionAdminReader::stats()), computed once per request, never per row.
 *
 * Explanations are InfoHint icons (admin-ui-standard.md §1), never a paragraph under a field; short fields sit two per row on a wide container and one per row on a phone; the one warning the merchant must always see
 * (a public code without a condition) is visible text.
 */
class PromotionResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = PromotionModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-ticket';

    /** Per request: the listed records => promotion id => ['used' => n, 'scopes' => n]. */
    private static ?WeakMap $listStats = null;

    public static function getModelLabel(): string
    {
        return __('promotions.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('promotions.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('promotions.navigation_label');
    }

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::MARKETING;
    }

    public static function getNavigationSort(): ?int
    {
        return 10;
    }

    protected static function viewAnyPermission(): ?Permission
    {
        return Permission::PROMOTION_MANAGE;
    }

    protected static function viewPermission(): ?Permission
    {
        return Permission::PROMOTION_MANAGE;
    }

    protected static function createPermission(): ?Permission
    {
        return Permission::PROMOTION_MANAGE;
    }

    protected static function editPermission(): ?Permission
    {
        return Permission::PROMOTION_MANAGE;
    }

    /** A promotion is never deleted. */
    protected static function deletePermission(): ?Permission
    {
        return null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->orderByDesc('id');
    }

    // ---- the form ------------------------------------------------------------------------------------------

    public static function form(Schema $schema): Schema
    {
        $locked = fn (string $operation): bool => $operation === 'edit';

        return $schema
            ->extraAttributes(['novalidate' => true], merge: true)
            ->columns(1)
            ->components([
                Section::make(__('promotions.sections.code_and_discount'))->compact()->columns(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('code')
                        ->label(__('promotions.fields.code'))
                        ->afterLabel(fn (string $operation) => InfoHint::make(__($operation === 'edit' ? 'promotions.hints.code_locked' : 'promotions.hints.code'), 'code'))
                        ->maxLength(255)
                        ->disabled($locked)
                        ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                    Select::make('discount_type')
                        ->label(__('promotions.fields.discount_type'))
                        ->afterLabel(InfoHint::make(__('promotions.hints.discount_type'), 'discount_type'))
                        ->options([
                            PromotionDiscountType::PERCENTAGE->value => __('promotions.types.percentage'),
                            PromotionDiscountType::FIXED_AMOUNT->value => __('promotions.types.fixed_amount'),
                        ])
                        ->default(PromotionDiscountType::PERCENTAGE->value)
                        ->native(false)
                        ->selectablePlaceholder(false)
                        ->live()
                        ->disabled($locked)
                        ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                    TextInput::make('percentage')
                        ->label(__('promotions.fields.percentage'))
                        ->afterLabel(InfoHint::make(__('promotions.hints.percentage'), 'percentage'))
                        ->inputMode('decimal')
                        ->maxLength(6)
                        ->visible(fn (Get $get): bool => ($get('discount_type') ?? PromotionDiscountType::PERCENTAGE->value) === PromotionDiscountType::PERCENTAGE->value)
                        ->disabled($locked)
                        ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                    TextInput::make('discount_amount')
                        ->label(__('promotions.fields.discount_amount'))
                        ->afterLabel(InfoHint::make(__('promotions.hints.discount_amount'), 'discount_amount'))
                        ->inputMode('decimal')
                        ->maxLength(MoneyInput::MAX_LENGTH)
                        ->visible(fn (Get $get): bool => $get('discount_type') === PromotionDiscountType::FIXED_AMOUNT->value)
                        ->disabled($locked)
                        ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                    Toggle::make('is_active')
                        ->label(__('promotions.fields.is_active'))
                        ->afterLabel(InfoHint::make(__('promotions.hints.is_active'), 'is_active'))
                        ->default(true),
                ]),

                Section::make(__('promotions.sections.conditions'))->compact()->columns(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('minimum_spend')
                        ->label(__('promotions.fields.minimum_spend'))
                        ->afterLabel(InfoHint::make(__('promotions.hints.minimum_spend'), 'minimum_spend'))
                        ->inputMode('decimal')
                        ->maxLength(MoneyInput::MAX_LENGTH)
                        ->live(onBlur: true),
                    TextInput::make('maximum_spend')
                        ->label(__('promotions.fields.maximum_spend'))
                        ->afterLabel(InfoHint::make(__('promotions.hints.maximum_spend'), 'maximum_spend'))
                        ->inputMode('decimal')
                        ->maxLength(MoneyInput::MAX_LENGTH),
                    Toggle::make('new_customers_only')
                        ->label(__('promotions.fields.new_customers_only'))
                        ->afterLabel(InfoHint::make(__('promotions.hints.new_customers_only'), 'new_customers_only'))
                        ->live(),
                    Toggle::make('exclude_sale_items')
                        ->label(__('promotions.fields.exclude_sale_items'))
                        ->afterLabel(InfoHint::make(__('promotions.hints.exclude_sale_items'), 'exclude_sale_items')),
                    Toggle::make('individual_use_only')
                        ->label(__('promotions.fields.individual_use_only'))
                        ->afterLabel(InfoHint::make(__('promotions.hints.individual_use_only'), 'individual_use_only')),
                ]),

                Section::make(__('promotions.sections.limits'))->compact()->columns(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('usage_limit_total')
                        ->label(__('promotions.fields.usage_limit_total'))
                        ->afterLabel(InfoHint::make(__('promotions.hints.usage_limit_total'), 'usage_limit_total'))
                        ->inputMode('numeric')
                        ->maxLength(10)
                        ->live(onBlur: true),
                    TextInput::make('usage_limit_per_customer')
                        ->label(__('promotions.fields.usage_limit_per_customer'))
                        ->afterLabel(InfoHint::make(__('promotions.hints.usage_limit_per_customer'), 'usage_limit_per_customer'))
                        ->inputMode('numeric')
                        ->maxLength(10)
                        ->live(onBlur: true),
                    TextInput::make('usage_limit_items')
                        ->label(__('promotions.fields.usage_limit_items'))
                        ->afterLabel(InfoHint::make(__('promotions.hints.usage_limit_items'), 'usage_limit_items'))
                        ->inputMode('numeric')
                        ->maxLength(10)
                        ->live(onBlur: true),
                    DateTimePicker::make('valid_from')
                        ->label(__('promotions.fields.valid_from'))
                        ->afterLabel(InfoHint::make(__('promotions.hints.valid_from'), 'valid_from'))
                        ->seconds(false),
                    DateTimePicker::make('valid_until')
                        ->label(__('promotions.fields.valid_until'))
                        ->afterLabel(InfoHint::make(__('promotions.hints.valid_until'), 'valid_until'))
                        ->seconds(false)
                        ->live(),
                ]),

                Section::make(__('promotions.sections.scope'))->compact()->schema([
                    Repeater::make('scopes')
                        ->label(__('promotions.fields.scopes'))
                        ->afterLabel(InfoHint::make(__('promotions.hints.scopes'), 'scopes'))
                        ->default([])
                        ->live()
                        ->reorderable(false)
                        ->addActionLabel(__('promotions.actions.add_scope'))
                        ->columns(['default' => 1, 'md' => 3])
                        ->schema([
                            Select::make('type')
                                ->label(__('promotions.fields.scope_type'))
                                ->options(static::scopeTypeOptions())
                                ->native(false)
                                ->selectablePlaceholder(false)
                                ->default(PromotionScopeType::BRAND->value)
                                ->live()
                                ->afterStateUpdated(fn (Set $set) => $set('reference', null)),
                            Select::make('mode')
                                ->label(__('promotions.fields.scope_mode'))
                                ->options([
                                    PromotionScopeMode::INCLUDE->value => __('promotions.scope_modes.include'),
                                    PromotionScopeMode::EXCLUDE->value => __('promotions.scope_modes.exclude'),
                                ])
                                ->native(false)
                                ->selectablePlaceholder(false)
                                ->default(PromotionScopeMode::INCLUDE->value),
                            Select::make('reference')
                                ->label(__('promotions.fields.scope_reference'))
                                ->searchable()
                                ->preload()
                                ->options(fn (Get $get): array => static::scopeOptions($get('type'), ''))
                                ->getSearchResultsUsing(fn (string $search, Get $get): array => static::scopeOptions($get('type'), $search))
                                ->getOptionLabelUsing(fn ($value, Get $get): ?string => static::scopeLabel($get('type'), $value)),
                        ]),
                ]),

                Section::make(__('promotions.sections.public'))->compact()->columns(['default' => 1, 'md' => 2])->schema([
                    Toggle::make('is_public')
                        ->label(__('promotions.fields.is_public'))
                        ->afterLabel(InfoHint::make(__('promotions.hints.is_public'), 'is_public'))
                        ->live(),
                    TextInput::make('public_description')
                        ->label(__('promotions.fields.public_description'))
                        ->afterLabel(InfoHint::make(__('promotions.hints.public_description'), 'public_description'))
                        ->maxLength(120)
                        ->visible(fn (Get $get): bool => (bool) $get('is_public'))
                        ->columnSpanFull(),
                    View::make('filament.promotions.public-warning')
                        ->visible(fn (Get $get): bool => (bool) $get('is_public') && ! static::hasAnyCondition(
                            minimumSpend: $get('minimum_spend'),
                            scopes: $get('scopes'),
                            newCustomersOnly: (bool) $get('new_customers_only'),
                            validUntil: $get('valid_until'),
                            limits: [$get('usage_limit_total'), $get('usage_limit_per_customer'), $get('usage_limit_items')],
                        ))
                        ->columnSpanFull(),
                ]),

                HelpLink::group(['promo_overview', 'promo_scope', 'promo_public'], 'promotions'),
            ]);
    }

    /**
     * «Condition» for the warning of a public code = any of: a minimum spend, at least one scope, new customers only, a valid-until date, any usage limit.
     *
     * @param  array<int, mixed>|null  $scopes
     * @param  list<mixed>  $limits
     */
    public static function hasAnyCondition(mixed $minimumSpend, mixed $scopes, bool $newCustomersOnly, mixed $validUntil, array $limits): bool
    {
        if ($newCustomersOnly || filled($minimumSpend) || filled($validUntil) || (is_array($scopes) && $scopes !== [])) {
            return true;
        }

        foreach ($limits as $limit) {
            if (filled($limit)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private static function scopeTypeOptions(): array
    {
        $out = [];

        foreach (PromotionWriter::SCOPE_TYPES as $type) {
            $out[$type->value] = __('promotions.scope_types.'.$type->value);
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function scopeOptions(mixed $type, string $search): array
    {
        $enum = is_string($type) ? PromotionScopeType::tryFrom($type) : null;

        return $enum !== null && in_array($enum, PromotionWriter::SCOPE_TYPES, true) ? app(PromotionAdminReader::class)->searchOptions($enum, $search) : [];
    }

    private static function scopeLabel(mixed $type, mixed $value): ?string
    {
        $enum = is_string($type) ? PromotionScopeType::tryFrom($type) : null;

        return $enum !== null && is_scalar($value) ? app(PromotionAdminReader::class)->label($enum, (string) $value) : null;
    }

    /**
     * The form's state for a stored promotion (the edit page, and «Дублирай» with the code left empty).
     *
     * @return array<string, mixed>
     */
    public static function stateFor(PromotionModel $model, bool $duplicate = false): array
    {
        $currency = DefaultCurrency::get();
        $money = static fn (?int $minor): ?string => $minor === null ? null : Money::fromMinorUnits($minor, $currency)->decimalValue();

        $scopes = PromotionScopeModel::query()->where('promotion_id', $model->id)->orderBy('id')->get()
            ->filter(static fn (PromotionScopeModel $scope): bool => in_array(PromotionScopeType::tryFrom((string) $scope->scope_type), PromotionWriter::SCOPE_TYPES, true))
            ->map(static fn (PromotionScopeModel $scope): array => ['type' => $scope->scope_type, 'mode' => $scope->mode, 'reference' => (string) $scope->scope_reference_id])
            ->values()->all();

        return [
            'code' => $duplicate ? '' : $model->code,
            'discount_type' => $model->discount_type,
            'percentage' => $model->discount_percentage_basis_points !== null ? PromotionWriter::percentText((int) $model->discount_percentage_basis_points) : null,
            'discount_amount' => $money($model->discount_amount_minor),
            'minimum_spend' => $money($model->minimum_spend_amount_minor),
            'maximum_spend' => $money($model->maximum_spend_amount_minor),
            'new_customers_only' => (bool) $model->new_customers_only,
            'exclude_sale_items' => (bool) $model->exclude_sale_items,
            'individual_use_only' => (bool) $model->individual_use_only,
            'usage_limit_total' => $model->usage_limit_total,
            'usage_limit_per_customer' => $model->usage_limit_per_customer,
            'usage_limit_items' => $model->usage_limit_items,
            'valid_from' => $model->valid_from?->format('Y-m-d H:i:s'),
            'valid_until' => $model->valid_until?->format('Y-m-d H:i:s'),
            'is_active' => $duplicate ? true : $model->status === 'active',
            'is_public' => (bool) $model->is_public,
            'public_description' => $model->public_description,
            'scopes' => $scopes,
        ];
    }

    // ---- the list ------------------------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->emptyStateHeading(__('promotions.table.empty'))
            ->emptyStateDescription(__('promotions.table.empty_description'))
            ->columns([
                TextColumn::make('code')
                    ->label(__('promotions.fields.code'))
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('discount')
                    ->label(__('promotions.table.discount'))
                    ->state(fn (PromotionModel $record): string => static::discountText($record)),
                TextColumn::make('conditions')
                    ->label(__('promotions.table.conditions'))
                    ->state(fn (PromotionModel $record, $livewire): string => static::conditionsText($record, static::stats($livewire)[(string) $record->id]['scopes'] ?? 0))
                    ->wrap(),
                TextColumn::make('validity')
                    ->label(__('promotions.table.validity'))
                    ->state(fn (PromotionModel $record): string => static::validityText($record)),
                TextColumn::make('used')
                    ->label(__('promotions.table.used'))
                    ->state(function (PromotionModel $record, $livewire): string {
                        $used = static::stats($livewire)[(string) $record->id]['used'] ?? 0;

                        return $record->usage_limit_total !== null
                            ? __('promotions.table.used_of', ['used' => $used, 'limit' => $record->usage_limit_total])
                            : (string) $used;
                    }),
                TextColumn::make('status')
                    ->label(__('promotions.table.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'active' ? __('promotions.table.active') : __('promotions.table.inactive'))
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
                TextColumn::make('is_public')
                    ->label(__('promotions.table.public'))
                    ->badge()
                    ->state(fn (PromotionModel $record): ?string => $record->is_public ? __('promotions.table.public_badge') : null)
                    ->color('info'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('promotions.table.filter_status'))
                    ->options(['active' => __('promotions.table.active'), 'inactive' => __('promotions.table.inactive')]),
                TernaryFilter::make('is_public')
                    ->label(__('promotions.table.filter_public'))
                    ->trueLabel(__('promotions.table.filter_public_yes'))
                    ->falseLabel(__('promotions.table.filter_public_no'))
                    ->placeholder('—'),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('edit_promotion')
                        ->label(__('promotions.actions.edit'))
                        ->icon('heroicon-o-pencil-square')
                        ->url(fn (PromotionModel $record): string => static::getUrl('edit', ['record' => $record]))
                        ->visible(fn (PromotionModel $record): bool => static::canEdit($record)),
                    Action::make('duplicate_promotion')
                        ->label(__('promotions.actions.duplicate'))
                        ->icon('heroicon-o-document-duplicate')
                        ->url(fn (PromotionModel $record): string => static::getUrl('create', ['duplicate' => $record->id]))
                        ->visible(fn (): bool => static::canCreate()),
                    static::statusAction(),
                ])
                    ->label(__('promotions.actions.menu'))
                    ->icon('heroicon-m-chevron-down')
                    ->iconPosition(IconPosition::After)
                    ->color('gray')
                    ->button(),
            ]);
    }

    /** Switches a promotion on or off: through PromotionWriter (the domain's own activate() / deactivate()), the one place that writes. */
    private static function statusAction(): Action
    {
        return Action::make('toggle_promotion')
            ->label(fn (PromotionModel $record): string => $record->status === 'active' ? __('promotions.actions.deactivate') : __('promotions.actions.activate'))
            ->icon(fn (PromotionModel $record): string => $record->status === 'active' ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
            ->visible(fn (PromotionModel $record): bool => static::canEdit($record))
            ->action(function (PromotionModel $record): void {
                $turnOn = $record->status !== 'active';

                try {
                    app(PromotionWriter::class)->setActive((string) $record->id, $turnOn);
                } catch (PromotionInvalidException $exception) {
                    Notification::make()->title(__('promotions.errors.invalid'))->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title($turnOn ? __('promotions.notice.activated') : __('promotions.notice.deactivated'))->success()->send();
            });
    }

    /**
     * Used count and scope count of the promotions the table lists — read ONCE per request (two grouped reads for all of them).
     *
     * @return array<string, array{used: int, scopes: int}>
     */
    private static function stats($livewire): array
    {
        $records = $livewire->getTableRecords();

        static::$listStats ??= new WeakMap();

        return static::$listStats[$records] ??= app(PromotionAdminReader::class)->stats($records->pluck('id')->all());
    }

    public static function discountText(PromotionModel $record): string
    {
        if ($record->discount_type === PromotionDiscountType::PERCENTAGE->value) {
            $text = PromotionWriter::percentText((int) $record->discount_percentage_basis_points);

            return (str_starts_with(app()->getLocale(), 'bg') ? str_replace('.', ',', $text) : $text).' %';
        }

        return static::money($record->discount_amount_minor, $record->discount_amount_currency);
    }

    public static function conditionsText(PromotionModel $record, int $scopeCount): string
    {
        $parts = [];

        $record->minimum_spend_amount_minor !== null && $parts[] = __('promotions.table.min', ['amount' => static::money($record->minimum_spend_amount_minor, $record->minimum_spend_amount_currency)]);
        $record->maximum_spend_amount_minor !== null && $parts[] = __('promotions.table.max', ['amount' => static::money($record->maximum_spend_amount_minor, $record->maximum_spend_amount_currency)]);
        $record->new_customers_only && $parts[] = __('promotions.table.new_customers');
        $record->exclude_sale_items && $parts[] = __('promotions.table.no_sale_items');
        $record->individual_use_only && $parts[] = __('promotions.table.individual');
        $scopeCount > 0 && $parts[] = __('promotions.table.scopes', ['count' => $scopeCount]);

        return $parts === [] ? __('promotions.table.conditions_none') : implode(', ', $parts);
    }

    public static function validityText(PromotionModel $record): string
    {
        $until = $record->valid_until?->format('d.m.Y');
        $from = $record->valid_from?->format('d.m.Y');

        if ($until !== null) {
            return __('promotions.table.until', ['date' => $until]);
        }

        return $from !== null ? __('promotions.table.from', ['date' => $from]) : __('promotions.table.no_limit');
    }

    private static function money(?int $minor, ?string $currency): string
    {
        if ($minor === null || $currency === null) {
            return '';
        }

        $money = Money::fromMinorUnits($minor, $currency);

        return app(PriceDisplayFormatter::class)->format($money->decimalValue(), $money->currency());
    }

    // ---- shared by the pages -------------------------------------------------------------------------------

    /**
     * A writer refusal as the form's own field errors (the form's state lives under `data`).
     *
     * @throws ValidationException
     */
    public static function fieldErrors(PromotionInvalidException $exception): never
    {
        $messages = [];

        foreach ($exception->errors as $field => $list) {
            $messages['data.'.$field] = $list;
        }

        throw ValidationException::withMessages($messages);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromotions::route('/'),
            'create' => CreatePromotion::route('/create'),
            'edit' => EditPromotion::route('/{record}/edit'),
        ];
    }
}
