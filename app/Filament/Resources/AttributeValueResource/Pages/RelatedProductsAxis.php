<?php

namespace App\Filament\Resources\AttributeValueResource\Pages;

use App\Filament\Resources\AttributeValueResource;
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
 * The drill-down behind AttributeValue's AXIS count column — same hard
 * "no selection UI at all" rule as
 * AttributeDefinitionResource\Pages\RelatedProductsAxis; see that
 * class's own docblock for the full reasoning. Query mirrors
 * EloquentAttributeValueRepository::countProductsUsing()'s own axis
 * branch exactly (DISTINCT product_id via
 * catalog_variation_attribute_values -> catalog_variations).
 */
class RelatedProductsAxis extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = AttributeValueResource::class;

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(AttributeValueResource::canView($this->record), 403);
    }

    public function getTitle(): string
    {
        return $this->record->value;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        $valueId = $this->record->id;

        return $table
            ->query(
                ProductModel::query()->whereIn('id', function ($query) use ($valueId): void {
                    $query->select('catalog_variations.product_id')
                        ->from('catalog_variation_attribute_values')
                        ->join('catalog_variations', 'catalog_variations.id', '=', 'catalog_variation_attribute_values.variation_id')
                        ->where('catalog_variation_attribute_values.attribute_value_id', $valueId)
                        ->distinct();
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
