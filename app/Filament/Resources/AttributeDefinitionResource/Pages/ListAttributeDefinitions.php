<?php

namespace App\Filament\Resources\AttributeDefinitionResource\Pages;

use App\Filament\Resources\AttributeDefinitionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAttributeDefinitions extends ListRecords
{
    protected static string $resource = AttributeDefinitionResource::class;

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
