<?php

namespace App\Filament\Resources\StaffResource\Pages;

use App\Filament\Resources\StaffResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStaff extends ListRecords
{
    protected static string $resource = StaffResource::class;

    /**
     * See RoleResource\Pages\ListRoles's identical override for why
     * this is necessary — ListRecords::authorizeAccess() is an empty
     * no-op by default in the installed Filament v5.8.1, so canViewAny()
     * would otherwise only hide the navigation link, not actually block
     * a direct visit to this page's URL.
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
