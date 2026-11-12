<?php

namespace App\Filament\Resources\CollectionResource\Pages;

use App\Filament\Resources\CollectionResource;
use Filament\Resources\Pages\ViewRecord;

/** Read-only. See RoleResource\Pages\ViewRole's identical note. */
class ViewCollection extends ViewRecord
{
    protected static string $resource = CollectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CollectionResource::deleteAction(),
        ];
    }
}
