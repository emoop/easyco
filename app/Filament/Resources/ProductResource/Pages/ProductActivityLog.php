<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\ActivityLogModel;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Lang;

/**
 * The read-only drill-down behind ViewProduct's "History" header action
 * — mirrors RelatedProducts' exact Page+HasTable+InteractsWithTable
 * shape (a real, dedicated Filament Page, same reasoning as that
 * class's own docblock), minus RunsBulkUnlink: this page has no bulk
 * action at all, nothing to select or mutate, purely a read of
 * activity_log.
 */
class ProductActivityLog extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ProductResource::class;

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);

        // Browsing history is a read operation, not an edit one — the
        // same viewPermission()/PRODUCT_VIEW check as ViewProduct
        // itself, not editPermission().
        abort_unless(ProductResource::canView($this->record), 403);
    }

    public function getTitle(): string
    {
        return __('products.activity_log.title').': '.$this->record->name;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ActivityLogModel::query()
                    ->where('entity_type', 'product')
                    ->where('entity_id', (string) $this->record->id)
            )
            // 'id' desc is the real, deterministic tie-breaker: occurred_at
            // is a second-precision timestamp, so two entries written
            // within the same request (e.g. renaming AND re-statusing a
            // product in one save) can share an identical value — id
            // still reflects true insertion order in that case.
            // defaultSort() only ever takes ONE column as a plain string
            // (confirmed against the installed Table\Concerns\
            // CanSortRecords::defaultSort() signature — calling it twice
            // just overwrites), so a genuine two-column sort needs the
            // Closure-returning-Builder form instead.
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('occurred_at')->orderByDesc('id'))
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('products.activity_log.columns.occurred_at'))
                    ->dateTime(),
                TextColumn::make('action')
                    ->label(__('products.activity_log.columns.action'))
                    ->formatStateUsing(fn (ActivityLogModel $record): string => static::actionLabel($record)),
                TextColumn::make('old_value')
                    ->label(__('products.activity_log.columns.old_value')),
                TextColumn::make('new_value')
                    ->label(__('products.activity_log.columns.new_value')),
                TextColumn::make('staff_name')
                    ->label(__('products.activity_log.columns.staff_name'))
                    ->formatStateUsing(fn (?string $state): string => $state ?? __('products.activity_log.system_actor')),
            ]);
    }

    /**
     * 'created' -> a fixed translated label. 'updated' -> the field's
     * own human label, reusing products.fields.* (name/slug/status/...)
     * where one already exists rather than duplicating it, per this
     * task's own instruction; a descriptive-attribute field (keyed by
     * its AttributeDefinition code, not a products.fields.* concept)
     * falls back to that definition's real, current name, and finally
     * to the raw field string if even the definition itself is gone.
     */
    protected static function actionLabel(ActivityLogModel $record): string
    {
        if ($record->action === 'created') {
            return __('products.activity_log.action_created');
        }

        $field = (string) $record->field;

        if (Lang::has("products.fields.{$field}")) {
            return __("products.fields.{$field}");
        }

        return AttributeDefinitionModel::where('code', $field)->value('name') ?? $field;
    }
}
