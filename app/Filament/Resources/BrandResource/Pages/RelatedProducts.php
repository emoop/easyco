<?php

namespace App\Filament\Resources\BrandResource\Pages;

use App\Enums\CatalogLookupKind;
use App\Filament\Concerns\RunsBulkUnlink;
use App\Filament\Resources\BrandResource;
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
 * The drill-down view behind Brand's product-count column —
 * admin-panel-design.md's Part B. A real, dedicated Filament Page, not
 * a modal: a live interactive Table with row selection and a bulk
 * action needs Tables\Concerns\InteractsWithTable on a genuine
 * Livewire component (the same mechanism ListRecords itself uses),
 * and Filament\Actions\Action::modalContent() only ever accepts
 * static View|Htmlable|Closure content — confirmed directly against
 * the installed v5.8.1 source before choosing this shape.
 *
 * No ProductResource exists yet in this project (confirmed) — rows
 * are plain, non-linked data (name/base_sku/status) for recognition
 * only.
 */
class RelatedProducts extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;
    use RunsBulkUnlink;

    protected static string $resource = BrandResource::class;

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(BrandResource::canView($this->record), 403);
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
        return $table
            ->query(ProductModel::query()->where('brand_id', $this->record->id))
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
                    ->visible(fn (): bool => BrandResource::canEdit($this->record))
                    ->action(function (Collection $records): void {
                        $this->runBulkUnlink(
                            $records->pluck('id'),
                            CatalogLookupKind::BRAND,
                            (string) $this->record->id,
                        );
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }
}
