<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    /**
     * See RoleResource\Pages\ListRoles's identical override — Filament
     * v5.8.1's ListRecords::authorizeAccess() is still a no-op by
     * default, so canViewAny() alone would only hide the navigation
     * link, not actually block a direct visit to this page's URL.
     */
    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    /**
     * Two explicit entry points, per admin-panel-design.md §13.1: a
     * SIMPLE product (the existing single-page flow) and a VARIABLE
     * product (the new wizard, CreateVariableProduct — Step A only,
     * Axes/Variations grid/price/stock are separate, later steps).
     * CreateAction::make()'s own ->label() is overridden here rather
     * than left at its Filament default ("Create Product") — confirmed
     * real and overridable against the installed v5.8.1 source
     * (CreateAction::setUp() only sets a default via ->label(), which
     * a later ->label() call on the same fluent chain replaces).
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('products.create_simple_action')),
            Action::make('create_variable')
                ->label(__('products.create_variable_action'))
                ->url(fn (): string => ProductResource::getUrl('create-variable'))
                ->visible(fn (): bool => ProductResource::canCreate()),
        ];
    }
}
