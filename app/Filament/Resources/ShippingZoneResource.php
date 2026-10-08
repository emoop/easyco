<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\ShippingZoneResource\Pages\CreateShippingZone;
use App\Filament\Resources\ShippingZoneResource\Pages\EditShippingZone;
use App\Filament\Resources\ShippingZoneResource\Pages\ListShippingZones;
use App\Filament\Support\HelpLink;
use App\Services\Exceptions\ShippingZoneInUseException;
use App\Services\Exceptions\ShippingZoneInvalidException;
use App\Services\Exceptions\ShippingZoneNotFoundException;
use App\Services\SettlementNormalizerResolver;
use App\Services\ShippingZoneCoverageReader;
use App\Services\ShippingZoneReorderer;
use App\Services\ShippingZoneWriter;
use App\Settings\CountryNames;
use App\Settings\StoreLocale;
use BackedEnum;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Matching\PostcodeNormalizer;
use EasyCo\Shipping\Persistence\Eloquent\ShippingZoneModel;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use WeakMap;

/**
 * Shipping zones (shipping-domain-design.md §12.3.2, stage 5c): the ordered list — because the ORDER IS THE RULE,
 * the first zone from the top that matches the address wins — and the create / edit form. EVERY write is one call
 * to a service (ShippingZoneWriter, ShippingZoneReorderer): a Filament form or action never writes a shipping
 * row itself, and every validation message on the form is the service's, translated. The permission is
 * `shipping_manage` for everything; the Eloquent model is the package's read model for the table only.
 *
 * sortOrder is NOT a field of the form: the order changes only through Move up / Move down (a single way to
 * change the rule — §12.3.2's optional sortOrder field is deliberately not offered).
 *
 * THE LIST READS IN A BOUNDED NUMBER OF QUERIES whatever the number of zones: the zones once (this resource's
 * query, in match order), and the methods of ALL listed zones once through ShippingMethodRepository::forZones()
 * (methods + their rates), plus the page's own auth/settings reads. Positions and counts are computed once per
 * request, never per row.
 */
class ShippingZoneResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = ShippingZoneModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-map';

    /** Per request: the listed records => ['position' => [id => 1..n], 'methods' => [id => count]]. */
    private static ?WeakMap $listFacts = null;

    public static function getModelLabel(): string
    {
        return __('shipping.zones.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('shipping.zones.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('shipping.zones.navigation_label');
    }

    /** Right after the Shipping page (sort 10). */
    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::SHIPPING;
    }

    public static function getNavigationSort(): ?int
    {
        return 20;
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

    /** The match order — sortOrder ASC, id ASC — the same as ShippingZoneRepository::allOrdered(). */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->orderBy('sort_order')->orderBy('id');
    }

    // ---- the form ------------------------------------------------------------------------------------------

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('name')
                ->label(__('shipping.zones.fields.name'))
                ->required()
                ->maxLength(255),
            Select::make('country_codes')
                ->label(__('shipping.zones.fields.country_codes'))
                ->multiple()
                ->searchable()
                ->required()
                ->options(fn (): array => CountryNames::forLocale(app(StoreLocale::class)->current())),
            TagsInput::make('settlement_names')
                ->label(__('shipping.zones.fields.settlement_names'))
                ->helperText(__('shipping.zones.help.settlement_names'))
                ->live(),
            Text::make(fn (Get $get): HtmlString => static::preview(
                $get('settlement_names'),
                'settlements',
                static fn (string $entry): string => app(SettlementNormalizerResolver::class)->forCurrentLocale()->normalize($entry),
            ))->color('gray')->size('sm'),
            TagsInput::make('postcodes')
                ->label(__('shipping.zones.fields.postcodes'))
                ->helperText(__('shipping.zones.help.postcodes'))
                ->live(),
            Text::make(fn (Get $get): HtmlString => static::preview(
                $get('postcodes'),
                'postcodes',
                static fn (string $entry): string => PostcodeNormalizer::normalize($entry),
            ))->color('gray')->size('sm'),
            HelpLink::group(['zone_editor', 'zone_settlement_matching'], 'shipping'),
        ]);
    }

    /**
     * The matcher's OWN normalisation, shown (never re-implemented): each typed entry beside the form the matcher
     * compares, built with the SAME normalisers the matcher uses. At most 20 lines, then "and N more". Escaped.
     *
     * @param  \Closure(string): string  $normalize
     */
    private static function preview(mixed $entries, string $kind, \Closure $normalize): HtmlString
    {
        $entries = is_array($entries) ? array_values(array_filter($entries, 'is_string')) : [];

        if ($entries === []) {
            return new HtmlString('');
        }

        $lines = [];

        foreach (array_slice($entries, 0, 20) as $entry) {
            $lines[] = e(__('shipping.zones.preview.'.$kind, ['typed' => $entry, 'normalised' => $normalize($entry)]));
        }

        if (count($entries) > 20) {
            $lines[] = e(__('shipping.zones.preview.more', ['count' => count($entries) - 20]));
        }

        return new HtmlString('<strong>'.e(__('shipping.zones.preview.heading')).'</strong><br>'.implode('<br>', $lines));
    }

    // ---- the list ------------------------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->emptyStateHeading(__('shipping.zones.empty'))
            ->columns([
                TextColumn::make('position')
                    ->label(__('shipping.zones.fields.number'))
                    ->state(fn (ShippingZoneModel $record, $livewire): int => static::facts($livewire)['position'][(int) $record->id] ?? 0),
                TextColumn::make('name')
                    ->label(__('shipping.zones.fields.name'))
                    ->weight('bold'),
                TextColumn::make('coverage')
                    ->label(__('shipping.zones.fields.coverage'))
                    ->state(fn (ShippingZoneModel $record): string => app(ShippingZoneCoverageReader::class)->sentenceFor(
                        static::decodeList($record->country_codes) ?? [],
                        static::decodeList($record->settlement_names),
                        static::decodeList($record->postcodes),
                    ))
                    ->wrap(),
                TextColumn::make('methods')
                    ->label(__('shipping.zones.fields.methods'))
                    ->state(fn (ShippingZoneModel $record, $livewire): string => trans_choice('shipping.zones.methods_count', $count = static::facts($livewire)['methods'][(int) $record->id] ?? 0, ['count' => $count])),
            ])
            ->recordActions([
                ActionGroup::make([
                    static::editAction(),
                    static::moveAction('move_up', 'moveUp'),
                    static::moveAction('move_down', 'moveDown'),
                    static::deleteAction(),
                ])
                    ->label(__('shipping.zones.actions.menu'))
                    ->icon('heroicon-m-chevron-down')
                    ->iconPosition(IconPosition::After)
                    ->color('gray')
                    ->button(),
            ]);
    }

    /**
     * Positions (1..n) and method counts of the zones the table lists — read ONCE per request, never per row:
     * the methods of every listed zone in one grouped read.
     *
     * @return array{position: array<int, int>, methods: array<int, int>, last: int}
     */
    private static function facts($livewire): array
    {
        /** @var Collection<int, ShippingZoneModel> $records */
        $records = $livewire->getTableRecords();

        static::$listFacts ??= new WeakMap();

        return static::$listFacts[$records] ??= (function () use ($records): array {
            $ids = $records->pluck('id')->map(static fn ($id): string => (string) $id)->values()->all();
            $position = [];

            foreach ($ids as $index => $id) {
                $position[(int) $id] = $index + 1;
            }

            $methods = [];

            foreach (app(ShippingMethodRepository::class)->forZones($ids) as $zoneId => $zoneMethods) {
                $methods[(int) $zoneId] = count($zoneMethods);
            }

            return ['position' => $position, 'methods' => $methods, 'last' => count($ids)];
        })();
    }

    private static function editAction(): Action
    {
        return Action::make('edit_zone')
            ->label(__('shipping.zones.actions.edit'))
            ->icon('heroicon-o-pencil-square')
            ->url(fn (ShippingZoneModel $record): string => static::getUrl('edit', ['record' => $record]))
            ->visible(fn (ShippingZoneModel $record): bool => static::canEdit($record));
    }

    /** Move up / Move down: ONE call to the reorderer; the first row has no "up" and the last no "down" (the service is a no-op there anyway). */
    private static function moveAction(string $name, string $method): Action
    {
        return Action::make($name)
            ->label(__('shipping.zones.actions.'.$name))
            ->icon($name === 'move_up' ? 'heroicon-o-arrow-up' : 'heroicon-o-arrow-down')
            ->visible(function (ShippingZoneModel $record, $livewire) use ($name): bool {
                $facts = static::facts($livewire);
                $position = $facts['position'][(int) $record->id] ?? 0;

                return static::canEdit($record) && ($name === 'move_up' ? $position > 1 : $position < $facts['last']);
            })
            ->action(function (ShippingZoneModel $record) use ($method): void {
                try {
                    app(ShippingZoneReorderer::class)->{$method}((string) $record->id);
                } catch (ShippingZoneNotFoundException $exception) {
                    static::refused($exception->getMessage());

                    return;
                }

                Notification::make()->title(__('shipping.zones.notice.moved'))->success()->send();
            });
    }

    private static function deleteAction(): Action
    {
        return Action::make('delete_zone')
            ->label(__('shipping.zones.actions.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (ShippingZoneModel $record): string => __('shipping.zones.delete.heading', ['name' => $record->name]))
            ->modalDescription(__('shipping.zones.delete.description'))
            ->modalSubmitActionLabel(__('shipping.zones.delete.submit'))
            ->modalCancelActionLabel(__('orders.modal.close'))
            ->visible(fn (ShippingZoneModel $record): bool => static::canDelete($record))
            ->action(function (ShippingZoneModel $record): void {
                try {
                    app(ShippingZoneWriter::class)->delete((string) $record->id);
                } catch (ShippingZoneInUseException|ShippingZoneNotFoundException $exception) {
                    static::refused($exception->getMessage());

                    return;
                }

                Notification::make()->title(__('shipping.zones.notice.deleted'))->success()->send();
            });
    }

    private static function refused(string $message): void
    {
        Notification::make()->title(__('shipping.zones.notice.refused'))->body($message)->danger()->send();
    }

    // ---- shared by the pages -------------------------------------------------------------------------------

    /** @return list<string>|null */
    public static function decodeList(mixed $json): ?array
    {
        if ($json === null) {
            return null;
        }

        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? array_map('strval', $decoded) : null;
    }

    /**
     * A service refusal as the form's own field errors (the form's state lives under `data`).
     *
     * @throws ValidationException
     */
    public static function fieldErrors(ShippingZoneInvalidException $exception): never
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
            'index' => ListShippingZones::route('/'),
            'create' => CreateShippingZone::route('/create'),
            'edit' => EditShippingZone::route('/{record}/edit'),
        ];
    }
}
