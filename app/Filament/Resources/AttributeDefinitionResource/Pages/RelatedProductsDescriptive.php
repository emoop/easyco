<?php

namespace App\Filament\Resources\AttributeDefinitionResource\Pages;

use App\Enums\CatalogLookupKind;
use App\Filament\Concerns\RunsBulkUnlink;
use App\Filament\Resources\AttributeDefinitionResource;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use Filament\Actions\BulkAction;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * The drill-down behind AttributeDefinition's DESCRIPTIVE count column
 * only — normal selection/bulk-unlink, same shape as every other
 * drill-down. The axis-scoped drill-down is a deliberately separate
 * page (RelatedProductsAxis) with no selection UI at all — see that
 * class's own docblock for why this is a hard rule, not a default.
 */
class RelatedProductsDescriptive extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;
    use RunsBulkUnlink;

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
                        ->where('is_variation_axis', false);
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
            ])
            ->toolbarActions([
                BulkAction::make('detach')
                    ->label(__('related_products.bulk_unlink.button', ['entity' => $this->record->name]))
                    ->icon(Heroicon::XMark)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => AttributeDefinitionResource::canEdit($this->record))
                    ->action(function (Collection $records): void {
                        $this->runBulkUnlink(
                            $records->pluck('id'),
                            CatalogLookupKind::ATTRIBUTE_DEFINITION,
                            (string) $this->record->id,
                        );
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }
}
