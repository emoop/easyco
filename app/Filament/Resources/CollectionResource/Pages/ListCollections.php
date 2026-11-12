<?php

namespace App\Filament\Resources\CollectionResource\Pages;

use App\Filament\Resources\CollectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCollections extends ListRecords
{
    protected static string $resource = CollectionResource::class;

    /** See RoleResource\Pages\ListRoles's identical override. */
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
