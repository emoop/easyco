<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    /**
     * A real, confirmed gap in Filament v5.8.1's own default: unlike
     * CreateRecord/EditRecord, ListRecords::authorizeAccess() is an
     * empty no-op — canViewAny() only hides the navigation link, it does
     * NOT block a direct visit to the list page's URL. Overridden here
     * so viewAnyPermission() (Permission::STAFF_MANAGE) actually gates
     * this page, not just its sidebar entry.
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
