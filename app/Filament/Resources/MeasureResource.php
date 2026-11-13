<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\Design\InfoHint;
use App\Filament\NavigationGroup;
use App\Filament\Resources\MeasureResource\Pages\CreateMeasure;
use App\Filament\Resources\MeasureResource\Pages\EditMeasure;
use App\Filament\Resources\MeasureResource\Pages\ListMeasures;
use App\Filament\Support\MeasurementRefusals;
use App\Services\MeasureWriter;
use App\Services\MeasurementUsageReader;
use BackedEnum;
use EasyCo\Catalog\Enums\MeasureUnit;
use EasyCo\Catalog\Measure;
use EasyCo\Catalog\Persistence\Eloquent\MeasureModel;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Throwable;
use WeakMap;

/**
 * «Показатели» (MEAS-3, measurements-research.md «As built (MEAS-3)»): the merchant's list of things that are measured, each with ONE unit. A lookup of the Season's shape in the «Каталог» group, gated as
 * its neighbours are (PRODUCT_VIEW to see, TAXONOMY_MANAGE to change). EVERY write is one call to MeasureWriter (the refusals, the audit lines and the hooks are the writer's); no Filament action writes a
 * row itself, and every refusal the data layer can give is shown as its translated text (MeasurementRefusals).
 *
 * THE LIST READS IN A BOUNDED NUMBER OF QUERIES whatever the number of rows: the page once, and «Използва се в» for all listed rows in two grouped reads (MeasurementUsageReader), once per request.
 */
class MeasureResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = MeasureModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-arrows-pointing-out';

    /** Per request: the listed records => measure id => ['templates' => n, 'values' => n]. */
    private static ?WeakMap $listUsage = null;

    public static function getModelLabel(): string
    {
        return __('measurements.measure.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('measurements.measure.plural');
    }

    /** After the attribute (40) and attribute value (50) entries. */
    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::CATALOG;
    }

    public static function getNavigationSort(): ?int
    {
        return 52;
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
                ->label(__('measurements.measure.fields.name'))
                ->afterLabel(InfoHint::make(__('measurements.measure.help.name'), 'measure_name'))
                ->required()
                ->maxLength(Measure::NAME_MAX_LENGTH),
            Select::make('unit')
                ->label(__('measurements.measure.fields.unit'))
                ->afterLabel(InfoHint::make(__('measurements.measure.help.unit'), 'measure_unit'))
                ->options(static::unitOptions())
                ->required()
                // the unit is LOCKED once numbers exist (decided when the page opened; the server refuses a stale form as well)
                ->disabled(fn ($livewire): bool => static::unitLocked($livewire)),
            // a destructive-style warning the merchant must always see, so it stays visible (admin-ui-standard.md point 1)
            Text::make(__('measurements.measure.unit_locked'))->color('warning')->size('sm')
                ->visible(fn ($livewire): bool => static::unitLocked($livewire)),
            TextInput::make('sort')
                ->label(__('measurements.measure.fields.sort'))
                ->afterLabel(InfoHint::make(__('measurements.measure.help.sort'), 'measure_sort'))
                ->numeric()
                ->integer()
                ->minValue(0)
                ->required()
                ->default(fn (): int => ((int) MeasureModel::query()->max('sort')) + 1),
        ]);
    }

    private static function unitLocked($livewire): bool
    {
        return $livewire instanceof EditMeasure && $livewire->unitLocked;
    }

    /** @return array<string, string> */
    public static function unitOptions(): array
    {
        $options = [];

        foreach (MeasureUnit::cases() as $unit) {
            $options[$unit->value] = __('measurements.units.'.$unit->value);
        }

        return $options;
    }

    // ---- the list ------------------------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            // archived rows are hidden unless the merchant asks (a table-level rule: a filter's own base query is skipped while its toggle is off)
            ->modifyQueryUsing(fn (Builder $query, $livewire): Builder => ($livewire->tableFilters['show_archived']['isActive'] ?? false) ? $query : $query->where('is_active', true))
            ->emptyStateHeading(__('measurements.measure.empty'))
            ->emptyStateDescription(__('measurements.measure.empty_description'))
            ->recordClasses(fn (MeasureModel $record): ?string => $record->is_active ? null : 'opacity-60')
            ->columns([
                TextColumn::make('name')
                    ->label(__('measurements.measure.fields.name'))
                    ->weight('bold')
                    ->wrap(),
                TextColumn::make('unit')
                    ->label(__('measurements.measure.fields.unit'))
                    ->formatStateUsing(fn (string $state): string => __('measurements.units.'.$state)),
                TextColumn::make('sort')
                    ->label(__('measurements.measure.fields.sort')),
                TextColumn::make('is_active')
                    ->label(__('measurements.measure.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => __($state ? 'measurements.status.active' : 'measurements.status.archived'))
                    ->color(fn ($state): string => $state ? 'success' : 'gray'),
                TextColumn::make('used_by')
                    ->label(__('measurements.measure.fields.used_by'))
                    ->state(function (MeasureModel $record, $livewire): string {
                        $usage = static::usage($livewire)[(string) $record->id] ?? ['templates' => 0, 'values' => 0];

                        return trans_choice('measurements.used_by_templates', $usage['templates'], ['count' => $usage['templates']])
                            .' · '
                            .trans_choice('measurements.used_by_values', $usage['values'], ['count' => $usage['values']]);
                    })
                    ->wrap(),
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
     * «Използва се в» for the measures the table lists — read ONCE per request (two grouped reads for all of them).
     *
     * @return array<string, array{templates: int, values: int}>
     */
    private static function usage($livewire): array
    {
        $records = $livewire->getTableRecords();

        static::$listUsage ??= new WeakMap();

        return static::$listUsage[$records] ??= app(MeasurementUsageReader::class)->forMeasures($records->pluck('id')->all());
    }

    private static function inUse($livewire, MeasureModel $record): bool
    {
        $usage = static::usage($livewire)[(string) $record->id] ?? ['templates' => 0, 'values' => 0];

        return $usage['templates'] > 0 || $usage['values'] > 0;
    }

    private static function editAction(): Action
    {
        return Action::make('edit_measure')
            ->label(__('measurements.measure.actions.edit'))
            ->icon('heroicon-o-pencil-square')
            ->url(fn (MeasureModel $record): string => static::getUrl('edit', ['record' => $record]))
            ->visible(fn (MeasureModel $record): bool => static::canEdit($record));
    }

    private static function archiveAction(): Action
    {
        return Action::make('archive_measure')
            ->label(__('measurements.measure.actions.archive'))
            ->icon('heroicon-o-archive-box')
            ->color('gray')
            // a used measure cannot be deleted: this is where the merchant is pointed
            ->tooltip(fn (MeasureModel $record, $livewire): ?string => static::inUse($livewire, $record) ? __('measurements.archive_instead') : null)
            ->requiresConfirmation()
            ->modalHeading(fn (MeasureModel $record): string => __('measurements.measure.archive.heading', ['name' => $record->name]))
            ->modalDescription(__('measurements.measure.archive.description'))
            ->modalSubmitActionLabel(__('measurements.measure.archive.submit'))
            ->modalCancelActionLabel(__('orders.modal.close'))
            ->visible(fn (MeasureModel $record): bool => $record->is_active && static::canEdit($record))
            ->action(fn (MeasureModel $record) => static::run(fn () => app(MeasureWriter::class)->archive((string) $record->id), 'archived'));
    }

    private static function restoreAction(): Action
    {
        return Action::make('restore_measure')
            ->label(__('measurements.measure.actions.restore'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(fn (MeasureModel $record): string => __('measurements.measure.restore.heading', ['name' => $record->name]))
            ->modalDescription(__('measurements.measure.restore.description'))
            ->modalSubmitActionLabel(__('measurements.measure.restore.submit'))
            ->modalCancelActionLabel(__('orders.modal.close'))
            ->visible(fn (MeasureModel $record): bool => ! $record->is_active && static::canEdit($record))
            ->action(fn (MeasureModel $record) => static::run(fn () => app(MeasureWriter::class)->unarchive((string) $record->id), 'restored'));
    }

    /** Offered only for a measure nothing uses; a used one is archived instead. The writer's `measure_in_use` refusal is still translated (a measure used in the meantime). */
    private static function deleteAction(): Action
    {
        return Action::make('delete_measure')
            ->label(__('measurements.measure.actions.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (MeasureModel $record): string => __('measurements.measure.delete.heading', ['name' => $record->name]))
            ->modalDescription(__('measurements.measure.delete.description'))
            ->modalSubmitActionLabel(__('measurements.measure.delete.submit'))
            ->modalCancelActionLabel(__('orders.modal.close'))
            ->visible(fn (MeasureModel $record, $livewire): bool => static::canDelete($record) && ! static::inUse($livewire, $record))
            ->action(fn (MeasureModel $record) => static::run(fn () => app(MeasureWriter::class)->delete((string) $record->id), 'deleted'));
    }

    /** One row action: the writer's call, then the green notice; a data-layer refusal is the red translated one. */
    private static function run(callable $write, string $done): void
    {
        try {
            $write();
        } catch (Throwable $exception) {
            if (! MeasurementRefusals::isRefusal($exception)) {
                throw $exception;
            }

            MeasurementRefusals::notify($exception, 'measurements.measure.notice.refused');

            return;
        }

        Notification::make()->title(__('measurements.measure.notice.'.$done))->success()->send();
    }

    // ---- shared by the pages -------------------------------------------------------------------------------

    /**
     * A refusal of the writer as the form's own field error where it belongs (the form's state lives under `data`).
     *
     * @throws ValidationException
     */
    public static function fieldError(Throwable $exception): void
    {
        $field = match (MeasurementRefusals::reason($exception)) {
            'name_taken', 'invalid_name' => 'name',
            'unknown_unit' => 'unit',
            default => null,
        };

        if ($field !== null) {
            throw ValidationException::withMessages(['data.'.$field => MeasurementRefusals::message($exception)]);
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMeasures::route('/'),
            'create' => CreateMeasure::route('/create'),
            'edit' => EditMeasure::route('/{record}/edit'),
        ];
    }
}
