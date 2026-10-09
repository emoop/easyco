<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\ShippingMethodResource\Pages\CreateShippingMethod;
use App\Filament\Resources\ShippingMethodResource\Pages\EditShippingMethod;
use App\Filament\Resources\ShippingMethodResource\Pages\ListShippingMethods;
use App\Filament\Support\HelpLink;
use App\Services\Exceptions\ShippingMethodInUseException;
use App\Services\Exceptions\ShippingMethodInvalidException;
use App\Services\Exceptions\ShippingMethodNotFoundException;
use App\Services\MoneyInput;
use App\Services\PriceDisplayFormatter;
use App\Services\ShippingMethodCopier;
use App\Services\ShippingMethodInput;
use App\Services\ShippingMethodReorderer;
use App\Services\ShippingMethodSummaryReader;
use App\Services\ShippingMethodWriter;
use BackedEnum;
use Closure;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\Money;
use EasyCo\Shipping\Carrier\CarrierRegistry;
use EasyCo\Shipping\Contracts\ShippingClassRepository;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Enums\ShippingDeliveryType;
use EasyCo\Shipping\Enums\ShippingDestinationScope;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\Persistence\Eloquent\ShippingMethodModel;
use EasyCo\Shipping\Persistence\Eloquent\ShippingZoneModel;
use EasyCo\Shipping\ShippingCourier;
use EasyCo\Shipping\ShippingMethod;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use WeakMap;

/**
 * Shipping methods (shipping-domain-design.md §12.3.3, stage 5d): every zone's methods in one list — zones in match
 * order, then the method order inside each zone — and the create / edit form by kind. EVERY write is one call to a
 * service (ShippingMethodWriter, ShippingMethodReorderer, ShippingMethodCopier): a Filament form, toggle or action
 * never writes a shipping row itself, and every validation message on the form is the service's, translated. The
 * permission is `shipping_manage` for everything.
 *
 * The one-sentence summary of what a method does is ShippingMethodSummaryReader's — the single place it is built.
 * A method's order is changed only by Move up / Move down; its zone only by copying it elsewhere and deleting it
 * (a fact line says so).
 *
 * THE LIST READS IN A BOUNDED NUMBER OF QUERIES whatever the number of methods: the methods once (this resource's
 * query, which joins the zone for ordering and its name), the listed zones' methods with their class amounts once
 * through ShippingMethodRepository::forZones() (two reads, for the summaries and the positions), and the class names
 * once. Positions and summaries are computed once per request, never per row.
 */
class ShippingMethodResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = ShippingMethodModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-banknotes';

    /** Per request: the listed records => the entities, positions and class names the columns need. */
    private static ?WeakMap $listFacts = null;

    public static function getModelLabel(): string
    {
        return __('shipping.methods.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('shipping.methods.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('shipping.methods.navigation_label');
    }

    /** After the Shipping page (10) and the zones (20). */
    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::SHIPPING;
    }

    public static function getNavigationSort(): ?int
    {
        return 30;
    }

    protected static function viewAnyPermission(): ?Permission
    {
        return Permission::SHIPPING_MANAGE;
    }

    protected static function viewPermission(): ?Permission
    {
        return Permission::SHIPPING_MANAGE;
    }

    protected static function createPermission(): ?Permission
    {
        return Permission::SHIPPING_MANAGE;
    }

    protected static function editPermission(): ?Permission
    {
        return Permission::SHIPPING_MANAGE;
    }

    protected static function deletePermission(): ?Permission
    {
        return Permission::SHIPPING_MANAGE;
    }

    /** A carrier method is not configured here yet (§12.3.3): it can be switched, copied, moved and deleted, not edited. */
    public static function canEdit(Model $record): bool
    {
        return static::staffCanForAction(static::editPermission()) && $record->kind !== ShippingMethodKind::CARRIER->value;
    }

    /**
     * Zones in match order, then the methods of each zone in their order — ONE query. The zone's order and name come
     * from correlated sub-selects rather than a join, so the record lookups of the edit page (`where id = ?`) stay
     * unambiguous.
     */
    public static function getEloquentQuery(): Builder
    {
        $zone = static fn (string $column) => ShippingZoneModel::query()->select($column)->whereColumn('shipping_zones.id', 'shipping_methods.zone_id');

        return parent::getEloquentQuery()
            ->select('shipping_methods.*')
            ->selectSub($zone('name'), 'zone_name')
            ->orderBy($zone('sort_order'))
            ->orderBy('shipping_methods.zone_id')
            ->orderBy('shipping_methods.sort_order')
            ->orderBy('shipping_methods.id');
    }

    // ---- the form ------------------------------------------------------------------------------------------

    public static function form(Schema $schema): Schema
    {
        $currency = DefaultCurrency::get()->code();
        $classes = app(ShippingClassRepository::class)->all();
        $isMoney = static fn (string $kind): Closure => static fn (Get $get): bool => in_array($get('kind'), [ShippingMethodKind::FLAT->value, ShippingMethodKind::PER_CLASS->value], true);
        $isPerClass = static fn (Get $get): bool => $get('kind') === ShippingMethodKind::PER_CLASS->value;

        $rateFields = [];

        foreach ($classes as $class) {
            $code = (string) $class->code();

            $rateFields[] = TextInput::make('rates.'.$code)
                ->label($class->name())
                ->suffix($currency)
                ->inputMode('decimal')
                ->maxLength(21)
                ->visible($isPerClass)
                ->rules([fn (): Closure => static::signedAmountRule($currency)]);
        }

        return $schema->columns(1)->components([
            Select::make('zone_id')
                ->label(__('shipping.methods.fields.zone'))
                ->options(fn (): array => ShippingZoneModel::query()->orderBy('sort_order')->orderBy('id')->pluck('name', 'id')->all())
                ->required()
                // A method stays in its zone: moving it is a copy there and a delete here.
                ->disabled(fn (string $operation): bool => $operation === 'edit')
                ->dehydrated(fn (string $operation): bool => $operation === 'create'),
            Text::make(__('shipping.methods.facts.zone_fixed'))
                ->color('gray')->size('sm')
                ->visible(fn (string $operation): bool => $operation === 'edit'),
            TextInput::make('name')
                ->label(__('shipping.methods.fields.name'))
                ->required()
                ->maxLength(255),
            TextInput::make('courier')
                ->label(__('shipping.methods.fields.courier'))
                ->maxLength(ShippingCourier::MAX_LENGTH)
                ->datalist(fn (): array => static::courierSuggestions()),
            Text::make(__('shipping.methods.facts.grouping'))->color('gray')->size('sm'),
            // ONE field for where the method delivers (owner decision, stage 6d2). `delivers_to` is a UI value only —
            // no column, no domain concept — and becomes the stored (delivery-type label, destination scope) pair in
            // inputFrom() alone, through labelAndScopeFrom().
            Select::make('delivers_to')
                ->label(__('shipping.methods.fields.delivers_to'))
                ->options(static::deliversToOptions())
                ->default('any')
                ->required()
                ->live(),
            Text::make(fn (Get $get): string => static::deliversToHelp(is_string($get('delivers_to')) ? $get('delivers_to') : null))
                ->color('gray')->size('sm'),
            Select::make('kind')
                ->label(__('shipping.methods.fields.kind'))
                ->options(fn (?Model $record): array => static::kindOptions($record?->kind))
                ->default(ShippingMethodKind::FLAT->value)
                ->required()
                ->live(),
            Text::make(__('shipping.methods.facts.kind_clears'))->color('gray')->size('sm'),
            Text::make(__('shipping.methods.facts.carrier_fixed'))
                ->color('gray')->size('sm')
                ->visible(fn (Get $get): bool => $get('kind') === ShippingMethodKind::CARRIER->value),
            TextInput::make('price')
                ->label(__('shipping.methods.fields.price'))
                ->suffix($currency)
                ->inputMode('decimal')
                ->maxLength(20)
                ->visible($isMoney('price'))
                ->rules([fn (): Closure => static::amountRule($currency)]),
            TextInput::make('free_above')
                ->label(__('shipping.methods.fields.free_above'))
                ->suffix($currency)
                ->inputMode('decimal')
                ->maxLength(20)
                ->live(onBlur: true)
                ->visible($isMoney('free_above'))
                ->rules([fn (): Closure => static::amountRule($currency)]),
            Text::make(function (Get $get) use ($currency): string {
                $amount = MoneyInput::parse(is_scalar($get('free_above')) ? (string) $get('free_above') : null, $currency);

                return $amount === null || $amount->isZero() ? '' : __('shipping.methods.facts.free_above', ['amount' => static::format($amount)]);
            })->color('gray')->size('sm'),
            TextInput::make('carrier_code')
                ->label(__('shipping.methods.fields.carrier_code'))
                ->maxLength(64)
                ->visible(fn (Get $get): bool => $get('kind') === ShippingMethodKind::CARRIER->value),
            Radio::make('class_mode')
                ->label(__('shipping.methods.fields.class_mode'))
                ->options([
                    'replace' => __('shipping.methods.modes.replace'),
                    'adjust' => __('shipping.methods.modes.adjust'),
                ])
                ->default('replace')
                ->live()
                ->visible($isPerClass),
            Text::make(fn (Get $get): string => __('shipping.methods.facts.mode_'.($get('class_mode') === 'adjust' ? 'adjust' : 'replace')))
                ->color('gray')->size('sm')
                ->visible($isPerClass),
            Text::make($classes === [] ? __('shipping.methods.facts.no_classes') : __('shipping.methods.facts.class_amount_help'))
                ->color('gray')->size('sm')
                ->visible($isPerClass),
            ...$rateFields,
            Toggle::make('is_active')
                ->label(__('shipping.methods.fields.active'))
                ->default(true),
            // One line, five anchors: the first link is the plain help label, the rest are named by their
            // sections (method kinds, class mode, copying, courier grouping) — see HelpLink::group().
            HelpLink::group(['method_editor', 'method_kinds', 'class_mode', 'method_copy', 'method_grouping'], 'shipping'),
        ]);
    }

    /**
     * The couriers already used in the store, for the form's datalist: ONE distinct read on the form page only, at
     * most 50, in alphabetical order. A new name typed there simply starts a new group.
     *
     * @return list<string>
     */
    public static function courierSuggestions(): array
    {
        return ShippingMethodModel::query()
            ->whereNotNull('courier')
            ->distinct()
            ->orderBy('courier')
            ->limit(50)
            ->pluck('courier')
            ->map(static fn ($courier): string => (string) $courier)
            ->all();
    }

    /** @return array<string, string> the ONE "Delivers to" Select's five options (owner decision, stage 6d2). */
    public static function deliversToOptions(): array
    {
        return [
            'any' => __('shipping.methods.delivers_to.any'),
            'address' => __('shipping.methods.delivers_to.address'),
            'pickup' => __('shipping.methods.delivers_to.pickup'),
            'office' => __('shipping.methods.delivers_to.office'),
            'locker' => __('shipping.methods.delivers_to.locker'),
        ];
    }

    /** The one-line explanation of the chosen "Delivers to" (stage 6d2); an unknown or missing value reads as `any`. */
    public static function deliversToHelp(?string $deliversTo): string
    {
        $key = array_key_exists((string) $deliversTo, static::deliversToOptions()) ? (string) $deliversTo : 'any';

        return __('shipping.methods.delivers_to_help.'.$key);
    }

    /**
     * The stored (label, scope) pair as the ONE "Delivers to" value the form shows (owner decision, stage 6d2). The
     * mapping table is authoritative: address+address, office+pickup and locker+pickup are their own options; a method
     * with no label or the `other` label maps by its scope; a pair the table cannot express (the DB CHECK forbids one)
     * reads as `any`.
     */
    public static function deliversToFrom(?string $deliveryType, string $scope): string
    {
        $plain = $deliveryType === null || $deliveryType === ShippingDeliveryType::OTHER->value;

        return match (true) {
            $deliveryType === ShippingDeliveryType::ADDRESS->value && $scope === ShippingDestinationScope::ADDRESS->value => 'address',
            $deliveryType === ShippingDeliveryType::OFFICE->value && $scope === ShippingDestinationScope::PICKUP->value => 'office',
            $deliveryType === ShippingDeliveryType::LOCKER->value && $scope === ShippingDestinationScope::PICKUP->value => 'locker',
            $plain && $scope === ShippingDestinationScope::PICKUP->value => 'pickup',
            $plain && $scope === ShippingDestinationScope::ADDRESS->value => 'address',
            default => 'any',
        };
    }

    /**
     * The "Delivers to" value as the stored (label, scope) pair — the ONE place the two are derived (stage 6d2). An
     * unknown value reads as `any`; the Select's own option list refuses one before this is reached.
     *
     * @return array{0: ?string, 1: string}
     */
    public static function labelAndScopeFrom(string $deliversTo): array
    {
        return match ($deliversTo) {
            'address' => [ShippingDeliveryType::ADDRESS->value, ShippingDestinationScope::ADDRESS->value],
            'pickup' => [null, ShippingDestinationScope::PICKUP->value],
            'office' => [ShippingDeliveryType::OFFICE->value, ShippingDestinationScope::PICKUP->value],
            'locker' => [ShippingDeliveryType::LOCKER->value, ShippingDestinationScope::PICKUP->value],
            default => [null, ShippingDestinationScope::ANY->value],
        };
    }

    /** CARRIER is offered only when a carrier is registered (none is in V1, §6) — or when the method being edited already is one. */
    private static function kindOptions(?string $currentKind): array
    {
        $kinds = [ShippingMethodKind::FLAT, ShippingMethodKind::FREE, ShippingMethodKind::PER_CLASS];

        if ($currentKind === ShippingMethodKind::CARRIER->value || app(CarrierRegistry::class)->all() !== []) {
            $kinds[] = ShippingMethodKind::CARRIER;
        }

        $options = [];

        foreach ($kinds as $kind) {
            $options[$kind->value] = __('shipping.methods.kinds.'.$kind->value);
        }

        return $options;
    }

    /** A typed amount must be an amount (MoneyInput's limits); blank is fine. */
    private static function amountRule(string $currency): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($currency): void {
            if (filled($value) && MoneyInput::parse((string) $value, $currency) === null) {
                $fail(__('shipping.methods.errors.amount_invalid'));
            }
        };
    }

    /** As amountRule(), with an optional leading sign (a class amount may be a discount in ADJUST mode). */
    private static function signedAmountRule(string $currency): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($currency): void {
            if (filled($value) && static::parseSigned((string) $value, $currency) === null) {
                $fail(__('shipping.methods.errors.amount_invalid'));
            }
        };
    }

    /**
     * A signed amount: an optional leading "+", "-" or "−" (U+2212), the rest through MoneyInput (its length and digit
     * limits). Null when it is not an amount.
     */
    public static function parseSigned(string $text, Currency|string $currency): ?Money
    {
        $text = trim($text);
        $negative = false;

        if ($text !== '' && in_array(mb_substr($text, 0, 1), ['+', '-', "\u{2212}"], true)) {
            $negative = mb_substr($text, 0, 1) !== '+';
            $text = mb_substr($text, 1);
        }

        $money = MoneyInput::parse($text, $currency);

        return $money !== null && $negative ? Money::fromMinorUnits(-$money->minorValue(), $currency) : $money;
    }

    private static function format(Money $money): string
    {
        return app(PriceDisplayFormatter::class)->format($money->decimalValue(), $money->currency());
    }

    // ---- form data <-> the service's input -----------------------------------------------------------------

    /**
     * The submitted form as the writer's input. A typed amount that is not an amount is a field error here (the form's
     * own rule normally catches it first); everything else is the writer's to refuse.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ShippingMethodInvalidException
     */
    public static function inputFrom(array $data): ShippingMethodInput
    {
        $currency = DefaultCurrency::get();
        $errors = [];
        $money = static function (string $field, mixed $raw, bool $signed = false) use ($currency, &$errors): ?Money {
            if (! filled($raw)) {
                return null;
            }

            $parsed = is_scalar($raw) ? ($signed ? static::parseSigned((string) $raw, $currency) : MoneyInput::parse((string) $raw, $currency)) : null;

            if ($parsed === null) {
                $errors[$field][] = __('shipping.methods.errors.amount_invalid');
            }

            return $parsed;
        };

        $price = $money('price', $data['price'] ?? null);
        $freeAbove = $money('free_above', $data['free_above'] ?? null);
        $rows = [];

        foreach ((array) ($data['rates'] ?? []) as $code => $raw) {
            if (filled($raw)) {
                $rows[] = ['class' => (string) $code, 'amount' => $money('class_rates', $raw, signed: true)];
            }
        }

        if ($errors !== []) {
            throw new ShippingMethodInvalidException($errors);
        }

        // The form's ONE "Delivers to" value becomes the stored (label, scope) pair here and nowhere else (stage 6d2).
        [$deliveryType, $destinationScope] = static::labelAndScopeFrom(is_string($data['delivers_to'] ?? null) ? $data['delivers_to'] : '');

        return new ShippingMethodInput(
            name: (string) ($data['name'] ?? ''),
            kind: (string) ($data['kind'] ?? ''),
            active: (bool) ($data['is_active'] ?? true),
            price: $price,
            freeAbove: $freeAbove,
            classMode: (string) ($data['class_mode'] ?? 'replace'),
            classRates: $rows,
            destinationScope: $destinationScope,
            carrierCode: isset($data['carrier_code']) ? (string) $data['carrier_code'] : null,
            courier: isset($data['courier']) ? (string) $data['courier'] : null,
            deliveryType: $deliveryType,
        );
    }

    /**
     * A stored method as the form's state.
     *
     * @return array<string, mixed>
     */
    public static function formStateOf(ShippingMethod $method): array
    {
        $rates = [];

        foreach ($method->classRates() as $code => $minor) {
            $rates[(string) $code] = Money::fromMinorUnits((int) $minor, DefaultCurrency::get())->decimalValue();
        }

        return [
            'zone_id' => $method->zoneId(),
            'name' => $method->name(),
            'kind' => $method->kind()->value,
            'is_active' => $method->isActive(),
            'price' => $method->amountMinor() === null ? null : Money::fromMinorUnits($method->amountMinor(), DefaultCurrency::get())->decimalValue(),
            'free_above' => $method->freeAboveMinor() === null ? null : Money::fromMinorUnits($method->freeAboveMinor(), DefaultCurrency::get())->decimalValue(),
            'class_mode' => $method->classMode()->value,
            'rates' => $rates,
            'delivers_to' => static::deliversToFrom($method->deliveryType()?->value, $method->destinationScope()->value),
            'carrier_code' => $method->carrierCode(),
            'courier' => $method->courier(),
        ];
    }

    /**
     * A service refusal as the form's own field errors (the form's state lives under `data`). The class amounts are
     * many fields: their refusal is shown on the mode choice and as a notice.
     *
     * @throws ValidationException
     */
    public static function fieldErrors(ShippingMethodInvalidException $exception): never
    {
        $messages = [];

        foreach ($exception->errors as $field => $list) {
            $messages['data.'.match ($field) {
                'class_rates' => 'class_mode',
                'zones' => 'zone_id',
                default => $field,
            }] = $list;
        }

        if (isset($exception->errors['class_rates'])) {
            static::refused($exception->errors['class_rates'][0]);
        }

        throw ValidationException::withMessages($messages);
    }

    // ---- the list ------------------------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->emptyStateHeading(__('shipping.methods.empty'))
            ->columns([
                TextColumn::make('zone_name')
                    ->label(__('shipping.methods.fields.zone')),
                TextColumn::make('position')
                    ->label(__('shipping.methods.fields.number'))
                    ->state(fn (ShippingMethodModel $record, $livewire): int => static::facts($livewire)['position'][(int) $record->id] ?? 0),
                TextColumn::make('courier')
                    ->label(__('shipping.methods.fields.courier'))
                    ->placeholder('—'),
                TextColumn::make('delivery_type')
                    ->label(__('shipping.methods.fields.delivery_type'))
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : __('shipping.methods.delivery_types.'.$state)),
                TextColumn::make('name')
                    ->label(__('shipping.methods.fields.name'))
                    ->weight('bold'),
                TextColumn::make('kind')
                    ->label(__('shipping.methods.fields.kind'))
                    ->formatStateUsing(fn (string $state): string => __('shipping.methods.kinds.'.$state)),
                TextColumn::make('summary')
                    ->label(__('shipping.methods.fields.summary'))
                    ->state(fn (ShippingMethodModel $record, $livewire): string => static::summaryOf($record, $livewire))
                    ->wrap(),
                TextColumn::make('class_mode')
                    ->label(__('shipping.methods.fields.class_mode'))
                    ->state(fn (ShippingMethodModel $record): string => $record->kind === ShippingMethodKind::PER_CLASS->value ? __('shipping.methods.modes.'.$record->class_mode) : '—'),
                ToggleColumn::make('is_active')
                    ->label(__('shipping.methods.fields.active'))
                    ->disabled(fn (): bool => ! static::canViewAny())
                    ->updateStateUsing(function (ShippingMethodModel $record, bool $state): bool {
                        try {
                            app(ShippingMethodWriter::class)->setActive((string) $record->id, $state);
                        } catch (ShippingMethodNotFoundException $exception) {
                            static::refused($exception->getMessage());

                            return ! $state;
                        }

                        Notification::make()->title(__('shipping.methods.notice.'.($state ? 'activated' : 'deactivated')))->success()->send();

                        return $state;
                    }),
                TextColumn::make('delivers_to')
                    ->label(__('shipping.methods.fields.delivers_to'))
                    ->state(fn (ShippingMethodModel $record): string => __('shipping.methods.delivers_to.'.static::deliversToFrom(is_string($record->delivery_type) ? $record->delivery_type : null, (string) $record->destination_scope))),
            ])
            ->filters([
                SelectFilter::make('zone_id')
                    ->label(__('shipping.methods.fields.zone'))
                    ->options(fn (): array => ShippingZoneModel::query()->orderBy('sort_order')->orderBy('id')->pluck('name', 'id')->all()),
                TernaryFilter::make('is_active')
                    ->label(__('shipping.methods.fields.active'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('shipping_methods.is_active', true),
                        false: fn (Builder $query): Builder => $query->where('shipping_methods.is_active', false),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                ActionGroup::make([
                    static::editAction(),
                    static::moveAction('move_up', 'moveUp'),
                    static::moveAction('move_down', 'moveDown'),
                    static::copyAction(),
                    static::deleteAction(),
                ])
                    ->label(__('shipping.methods.actions.menu'))
                    ->icon('heroicon-m-chevron-down')
                    ->iconPosition(IconPosition::After)
                    ->color('gray')
                    ->button(),
            ]);
    }

    /**
     * The entities of the listed methods (with their class amounts), their positions inside their zone and the class
     * names — read ONCE per request: the methods of every listed zone through one grouped read, the classes once.
     *
     * @return array{entities: array<int, ShippingMethod>, position: array<int, int>, count: array<int, int>, classNames: array<string, string>}
     */
    private static function facts($livewire): array
    {
        /** @var Collection<int, ShippingMethodModel> $records */
        $records = $livewire->getTableRecords();

        static::$listFacts ??= new WeakMap();

        return static::$listFacts[$records] ??= (function () use ($records): array {
            $zoneIds = $records->pluck('zone_id')->unique()->map(static fn ($id): string => (string) $id)->values()->all();
            $entities = [];
            $position = [];
            $count = [];

            foreach (app(ShippingMethodRepository::class)->forZones($zoneIds) as $zoneId => $methods) {
                $count[(int) $zoneId] = count($methods);

                foreach ($methods as $index => $method) {
                    $entities[(int) $method->id()] = $method;
                    $position[(int) $method->id()] = $index + 1;
                }
            }

            $classNames = [];

            foreach (app(ShippingClassRepository::class)->all() as $class) {
                $classNames[(string) $class->code()] = $class->name();
            }

            return ['entities' => $entities, 'position' => $position, 'count' => $count, 'classNames' => $classNames];
        })();
    }

    private static function summaryOf(ShippingMethodModel $record, $livewire): string
    {
        $facts = static::facts($livewire);
        $entity = $facts['entities'][(int) $record->id] ?? null;

        return $entity === null ? '' : app(ShippingMethodSummaryReader::class)->summary($entity, $facts['classNames'], withGrouping: false);
    }

    private static function editAction(): Action
    {
        return Action::make('edit_method')
            ->label(__('shipping.methods.actions.edit'))
            ->icon('heroicon-o-pencil-square')
            ->url(fn (ShippingMethodModel $record): string => static::getUrl('edit', ['record' => $record]))
            ->visible(fn (ShippingMethodModel $record): bool => static::canEdit($record));
    }

    /** Move up / Move down inside the zone: ONE call to the reorderer; the first has no "up" and the last no "down". */
    private static function moveAction(string $name, string $method): Action
    {
        return Action::make($name)
            ->label(__('shipping.methods.actions.'.$name))
            ->icon($name === 'move_up' ? 'heroicon-o-arrow-up' : 'heroicon-o-arrow-down')
            ->visible(function (ShippingMethodModel $record, $livewire) use ($name): bool {
                $facts = static::facts($livewire);
                $position = $facts['position'][(int) $record->id] ?? 0;

                return static::canViewAny() && ($name === 'move_up' ? $position > 1 : $position < ($facts['count'][(int) $record->zone_id] ?? 0));
            })
            ->action(function (ShippingMethodModel $record) use ($method): void {
                try {
                    app(ShippingMethodReorderer::class)->{$method}((string) $record->id);
                } catch (ShippingMethodNotFoundException $exception) {
                    static::refused($exception->getMessage());

                    return;
                }

                Notification::make()->title(__('shipping.methods.notice.moved'))->success()->send();
            });
    }

    private static function copyAction(): Action
    {
        return Action::make('copy_method')
            ->label(__('shipping.methods.actions.copy'))
            ->icon('heroicon-o-document-duplicate')
            ->modalHeading(fn (ShippingMethodModel $record): string => __('shipping.methods.copy.heading', ['name' => $record->name]))
            ->modalDescription(__('shipping.methods.copy.description'))
            ->modalSubmitActionLabel(__('shipping.methods.copy.submit'))
            ->modalCancelActionLabel(__('orders.modal.close'))
            ->schema(fn (ShippingMethodModel $record): array => HelpLink::append([
                Select::make('zones')
                    ->label(__('shipping.methods.copy.zones'))
                    ->multiple()
                    ->required()
                    ->maxItems(ShippingMethodCopier::MAX_TARGETS)
                    ->options(fn (): array => ShippingZoneModel::query()->where('id', '!=', $record->zone_id)->orderBy('sort_order')->orderBy('id')->pluck('name', 'id')->all()),
            ], 'method_copy', 'shipping'))
            ->visible(fn (): bool => static::canViewAny())
            ->action(function (array $data, ShippingMethodModel $record): void {
                try {
                    $result = app(ShippingMethodCopier::class)->copyToZones((string) $record->id, array_values((array) ($data['zones'] ?? [])));
                } catch (ShippingMethodInvalidException|ShippingMethodNotFoundException $exception) {
                    static::refused($exception->getMessage());

                    return;
                }

                $body = null;

                if ($result->zonesWithSameNameAndKind !== []) {
                    $names = ShippingZoneModel::query()->whereIn('id', $result->zonesWithSameNameAndKind)->pluck('name')->all();
                    $body = __('shipping.methods.copy.duplicates', ['zones' => implode(', ', $names)]);
                }

                Notification::make()
                    ->title(trans_choice('shipping.methods.copy.done', count($result->created), ['count' => count($result->created)]))
                    ->body($body)
                    ->success()
                    ->send();
            });
    }

    private static function deleteAction(): Action
    {
        return Action::make('delete_method')
            ->label(__('shipping.methods.actions.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (ShippingMethodModel $record): string => __('shipping.methods.delete.heading', ['name' => $record->name]))
            ->modalDescription(__('shipping.methods.delete.description'))
            ->modalSubmitActionLabel(__('shipping.methods.delete.submit'))
            ->modalCancelActionLabel(__('orders.modal.close'))
            ->visible(fn (ShippingMethodModel $record): bool => static::canDelete($record))
            ->action(function (ShippingMethodModel $record): void {
                try {
                    app(ShippingMethodWriter::class)->delete((string) $record->id);
                } catch (ShippingMethodInUseException|ShippingMethodNotFoundException $exception) {
                    static::refused($exception->getMessage());

                    return;
                }

                Notification::make()->title(__('shipping.methods.notice.deleted'))->success()->send();
            });
    }

    public static function refused(string $message): void
    {
        Notification::make()->title(__('shipping.methods.notice.refused'))->body($message)->danger()->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShippingMethods::route('/'),
            'create' => CreateShippingMethod::route('/create'),
            'edit' => EditShippingMethod::route('/{record}/edit'),
        ];
    }
}
