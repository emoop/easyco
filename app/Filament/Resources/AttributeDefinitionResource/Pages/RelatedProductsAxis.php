<?php

namespace App\Filament\Resources\AttributeDefinitionResource\Pages;

use App\Filament\Resources\AttributeDefinitionResource;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * The drill-down behind AttributeDefinition's AXIS count column —
 * visibility only, deliberately no checkboxes, no bulk action, no
 * selection UI at all. This is a hard rule, not a default that could
 * be loosened: axis usage is never bulk-unlinkable through this
 * mechanism, per the domain owner's own explicit reasoning — real,
 * possibly-sold Variation rows depend on it, and the only safe path is
 * resolving those Variations first, which is separate, future work
 * this page does not attempt. checkIfRecordIsSelectableUsing(fn () =>
 * false) is the actual enforcement (confirmed against the real
 * installed Filament v5.8.1 Table API); no toolbarActions() are
 * registered at all, so there is nothing to even try to call.
 */
class RelatedProductsAxis extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = AttributeDefinitionResource::class;

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(AttributeDefinitionResource::canView($this->record), 403);
    }

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        $definitionId = $this->record->id;

        return $table
            ->query(
                ProductModel::query()->whereIn('id', function ($query) use ($definitionId): void {
                    $query->select('product_id')
                        ->from('catalog_product_attributes')
                        ->where('attribute_definition_id', $definitionId)
                        ->where('is_variation_axis', true);
                })
            )
            ->columns([
                TextColumn::make('name')
                    ->label(__('related_products.columns.name'))
                    ->searchable(),
                TextColumn::make('base_sku')
                    ->label(__('related_products.columns.base_sku'))
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('related_products.columns.status'))
                    ->badge(),
                TextColumn::make('axis_note')
                    ->label(__('related_products.columns.axis_note'))
                    ->state(fn (): string => __('related_products.axis_note'))
                    ->color('gray'),
            ])
            ->checkIfRecordIsSelectableUsing(fn (): bool => false);
    }
}
