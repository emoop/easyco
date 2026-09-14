<?php

namespace App\Filament\Resources\BrandResource\Pages;

use App\Filament\Resources\BrandResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBrands extends ListRecords
{
    protected static string $resource = BrandResource::class;

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

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
