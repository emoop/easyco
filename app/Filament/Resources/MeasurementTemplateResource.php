<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\Design\InfoHint;
use App\Filament\NavigationGroup;
use App\Filament\Resources\MeasurementTemplateResource\Pages\CreateMeasurementTemplate;
use App\Filament\Resources\MeasurementTemplateResource\Pages\EditMeasurementTemplate;
use App\Filament\Resources\MeasurementTemplateResource\Pages\ListMeasurementTemplates;
use App\Filament\Support\MeasurementRefusals;
use App\Services\MeasurementTemplateWriter;
use App\Services\MeasurementUsageReader;
use BackedEnum;
use EasyCo\Catalog\MeasurementTemplate;
use EasyCo\Catalog\Persistence\Eloquent\MeasureModel;
use EasyCo\Catalog\Persistence\Eloquent\MeasurementTemplateModel;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;
use WeakMap;

/**
 * «Шаблони за размери» (MEAS-3): a named, ORDERED list of measures. Same shape, gating and rules as MeasureResource: every write is one call to MeasurementTemplateWriter, a refusal is a translated text, the
 * list reads a bounded number of queries (the measure names and the product counts of ALL listed rows, grouped, once per request), and archived rows are hidden behind «Покажи архивираните».
 *
 * THE MEASURE LIST of the form is a simple Repeater (drag or buttons to reorder, 0..20 items, each measure once): the choices are the ACTIVE measures plus the measures the template already has — even when
 * archived, shown muted with «(архивиран)» — so saving never silently drops one. A measure that is archived and NOT already in the template is refused on save.
 */
class MeasurementTemplateResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = MeasurementTemplateModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-table-cells';

    /** Per request: the listed records => ['products' => [id => n], 'names' => [id => string[]]]. */
    private static ?WeakMap $listUsage = null;

    /** Per Livewire component instance (one request): the options of the measure selects, read once, not once per repeater row. */
    private static ?WeakMap $optionCache = null;

    public static function getModelLabel(): string
    {
        return __('measurements.template.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('measurements.template.plural');
    }

    /** Right after «Показатели» (52). */
    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::CATALOG;
    }

    public static function getNavigationSort(): ?int
    {
        return 53;
    }

    protected static function viewAnyPermission(): ?Permission
    {
        return Permission::PRODUCT_VIEW;
    }

    protected static function viewPermission(): ?Permission
    {
        return Permission::PRODUCT_VIEW;
    }

    protected static function createPermission(): ?Permission
    {
        return Permission::TAXONOMY_MANAGE;
    }

    protected static function editPermission(): ?Permission
    {
        return Permission::TAXONOMY_MANAGE;
    }

    protected static function deletePermission(): ?Permission
    {
        return Permission::TAXONOMY_MANAGE;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->orderBy('sort')->orderBy('id');
    }

    // ---- the form ------------------------------------------------------------------------------------------

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('name')
                ->label(__('measurements.template.fields.name'))
                ->afterLabel(InfoHint::make(__('measurements.template.help.name'), 'template_name'))
                ->required()
                ->maxLength(MeasurementTemplate::NAME_MAX_LENGTH),
            TextInput::make('sort')
                ->label(__('measurements.template.fields.sort'))
                ->afterLabel(InfoHint::make(__('measurements.template.help.sort'), 'template_sort'))
                ->numeric()
                ->integer()
                ->minValue(0)
                ->required()
                ->default(fn (): int => ((int) MeasurementTemplateModel::query()->max('sort')) + 1),
            Repeater::make('measures')
                ->label(__('measurements.template.fields.measures'))
                ->afterLabel(InfoHint::make(__('measurements.template.help.measures'), 'template_measures'))
                ->simple(
                    Select::make('measure_id')
                        ->options(fn ($livewire): array => static::measureOptions($livewire))
                        ->required()
                        ->native(false)
                        ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                )
                ->addActionLabel(__('measurements.template.add_measure'))
                ->minItems(0)
                ->maxItems(MeasurementTemplate::MAX_MEASURES)
                ->defaultItems(0)
                ->reorderableWithButtons(),
        ]);
    }

    /**
     * The choices of one measure select: every active measure plus the measures the template being edited already has (archived ones are marked, so they stay visible and are kept on save).
     * Read once per request.
     *
     * @return array<string, string>
     */
    private static function measureOptions($livewire): array
    {
        static::$optionCache ??= new WeakMap();

        return static::$optionCache[$livewire] ??= (function () use ($livewire): array {
            $own = $livewire instanceof EditMeasurementTemplate ? $livewire->ownMeasureIds() : [];

            $query = MeasureModel::query()->orderBy('sort')->orderBy('id');
            $query->where(function (Builder $q) use ($own): void {
                $q->where('is_active', true);
                $own === [] || $q->orWhereIn('id', $own);
            });

            $options = [];

            foreach ($query->get() as $measure) {
                $label = $measure->name.' ('.__('measurements.units.'.$measure->unit).')';
                $options[(string) $measure->id] = $measure->is_active ? $label : $label.' — '.__('measurements.template.archived_marker');
            }

            return $options;
        })();
    }

    // ---- the list ------------------------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            // archived rows are hidden unless the merchant asks (a table-level rule: a filter's own base query is skipped while its toggle is off)
            ->modifyQueryUsing(fn (Builder $query, $livewire): Builder => ($livewire->tableFilters['show_archived']['isActive'] ?? false) ? $query : $query->where('is_active', true))
            ->emptyStateHeading(__('measurements.template.empty'))
            ->emptyStateDescription(__('measurements.template.empty_description'))
            ->recordClasses(fn (MeasurementTemplateModel $record): ?string => $record->is_active ? null : 'opacity-60')
            ->columns([
                TextColumn::make('name')
                    ->label(__('measurements.template.fields.name'))
                    ->weight('bold')
                    ->wrap(),
                TextColumn::make('measures')
                    ->label(__('measurements.template.fields.measures'))
                    ->state(function (MeasurementTemplateModel $record, $livewire): string {
                        $names = static::usage($livewire)['names'][(string) $record->id] ?? [];

                        return $names === [] ? __('measurements.template.no_measures') : implode(' · ', $names);
                    })
                    // a template with no measures is a draft: a warning chip, not a blank cell
                    ->badge(fn (MeasurementTemplateModel $record, $livewire): bool => (static::usage($livewire)['names'][(string) $record->id] ?? []) === [])
                    ->color(fn (MeasurementTemplateModel $record, $livewire): ?string => (static::usage($livewire)['names'][(string) $record->id] ?? []) === [] ? 'warning' : null)
                    ->wrap(),
                TextColumn::make('products')
                    ->label(__('measurements.template.fields.products'))
                    ->state(function (MeasurementTemplateModel $record, $livewire): string {
                        $count = static::usage($livewire)['products'][(string) $record->id] ?? 0;

                        return trans_choice('measurements.template.products_count', $count, ['count' => $count]);
                    }),
                TextColumn::make('is_active')
                    ->label(__('measurements.template.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => __($state ? 'measurements.status.active' : 'measurements.status.archived'))
                    ->color(fn ($state): string => $state ? 'success' : 'gray'),
            ])
            ->filters([
                Filter::make('show_archived')
                    ->label(__('measurements.filters.show_archived'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query)
                    ->indicateUsing(fn (array $data): ?string => ($data['isActive'] ?? false) ? __('measurements.filters.show_archived') : null),
            ])
            ->deferFilters(false)
            ->recordActions([
                static::editAction(),
                static::archiveAction(),
                static::restoreAction(),
                static::deleteAction(),
            ]);
    }

    /**
     * The measure names and the product counts of the templates the table lists — read ONCE per request (one grouped read each).
     *
     * @return array{products: array<string, int>, names: array<string, string[]>}
     */
    private static function usage($livewire): array
    {
        $records = $livewire->getTableRecords();

        static::$listUsage ??= new WeakMap();

        return static::$listUsage[$records] ??= (function () use ($records): array {
            $ids = $records->pluck('id')->all();
            $reader = app(MeasurementUsageReader::class);

            return ['products' => $reader->productsForTemplates($ids), 'names' => $reader->measureNamesForTemplates($ids)];
        })();
    }

    private static function inUse($livewire, MeasurementTemplateModel $record): bool
    {
        return (static::usage($livewire)['products'][(string) $record->id] ?? 0) > 0;
    }

    private static function editAction(): Action
    {
        return Action::make('edit_template')
            ->label(__('measurements.template.actions.edit'))
            ->icon('heroicon-o-pencil-square')
            ->url(fn (MeasurementTemplateModel $record): string => static::getUrl('edit', ['record' => $record]))
            ->visible(fn (MeasurementTemplateModel $record): bool => static::canEdit($record));
    }

    private static function archiveAction(): Action
    {
        return Action::make('archive_template')
            ->label(__('measurements.template.actions.archive'))
            ->icon('heroicon-o-archive-box')
            ->color('gray')
            ->tooltip(fn (MeasurementTemplateModel $record, $livewire): ?string => static::inUse($livewire, $record) ? __('measurements.archive_instead') : null)
            ->requiresConfirmation()
            ->modalHeading(fn (MeasurementTemplateModel $record): string => __('measurements.template.archive.heading', ['name' => $record->name]))
            ->modalDescription(__('measurements.template.archive.description'))
            ->modalSubmitActionLabel(__('measurements.template.archive.submit'))
            ->modalCancelActionLabel(__('orders.modal.close'))
            ->visible(fn (MeasurementTemplateModel $record): bool => $record->is_active && static::canEdit($record))
            ->action(fn (MeasurementTemplateModel $record) => static::run(fn () => app(MeasurementTemplateWriter::class)->archive((string) $record->id), 'archived'));
    }

    private static function restoreAction(): Action
    {
        return Action::make('restore_template')
            ->label(__('measurements.template.actions.restore'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(fn (MeasurementTemplateModel $record): string => __('measurements.template.restore.heading', ['name' => $record->name]))
            ->modalDescription(__('measurements.template.restore.description'))
            ->modalSubmitActionLabel(__('measurements.template.restore.submit'))
            ->modalCancelActionLabel(__('orders.modal.close'))
            ->visible(fn (MeasurementTemplateModel $record): bool => ! $record->is_active && static::canEdit($record))
            ->action(fn (MeasurementTemplateModel $record) => static::run(fn () => app(MeasurementTemplateWriter::class)->unarchive((string) $record->id), 'restored'));
    }

    /** Offered only for a template no product uses; the writer's `template_in_use` refusal is still translated (a product that picked it in the meantime). */
    private static function deleteAction(): Action
    {
        return Action::make('delete_template')
            ->label(__('measurements.template.actions.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (MeasurementTemplateModel $record): string => __('measurements.template.delete.heading', ['name' => $record->name]))
            ->modalDescription(__('measurements.template.delete.description'))
            ->modalSubmitActionLabel(__('measurements.template.delete.submit'))
            ->modalCancelActionLabel(__('orders.modal.close'))
            ->visible(fn (MeasurementTemplateModel $record, $livewire): bool => static::canDelete($record) && ! static::inUse($livewire, $record))
            ->action(fn (MeasurementTemplateModel $record) => static::run(fn () => app(MeasurementTemplateWriter::class)->delete((string) $record->id), 'deleted'));
    }

    private static function run(callable $write, string $done): void
    {
        try {
            $write();
        } catch (Throwable $exception) {
            if (! MeasurementRefusals::isRefusal($exception)) {
                throw $exception;
            }

            MeasurementRefusals::notify($exception, 'measurements.template.notice.refused');

            return;
        }

        Notification::make()->title(__('measurements.template.notice.'.$done))->success()->send();
    }

    // ---- shared by the pages -------------------------------------------------------------------------------

    /**
     * The measure ids of a submitted repeater, in order, with the server-side rule the select cannot enforce: a measure that is ARCHIVED may only stay if the template already had it.
     *
     * @param  array<int|string, mixed>  $submitted
     * @param  string[]  $already  the template's current measure ids
     * @return string[]
     *
     * @throws ValidationException
     */
    public static function measureIds(array $submitted, array $already = []): array
    {
        $ids = array_values(array_map('strval', array_filter($submitted, fn ($id) => filled($id))));

        $archived = array_map('strval', DB::table('catalog_measures')->whereIn('id', $ids)->where('is_active', false)->pluck('id')->all());

        foreach ($archived as $id) {
            if (! in_array($id, $already, true)) {
                throw ValidationException::withMessages(['data.measures' => __('measurements.template.errors.measure_archived')]);
            }
        }

        return $ids;
    }

    /**
     * A refusal of the writer as the form's own field error where it belongs (the form's state lives under `data`).
     *
     * @throws ValidationException
     */
    public static function fieldError(Throwable $exception): void
    {
        $field = match (MeasurementRefusals::reason($exception)) {
            'name_taken', 'invalid_name' => 'name',
            'invalid_measure_list', 'unknown_reference' => 'measures',
            default => null,
        };

        if ($field !== null) {
            throw ValidationException::withMessages(['data.'.$field => MeasurementRefusals::message($exception)]);
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMeasurementTemplates::route('/'),
            'create' => CreateMeasurementTemplate::route('/create'),
            'edit' => EditMeasurementTemplate::route('/{record}/edit'),
        ];
    }
}
