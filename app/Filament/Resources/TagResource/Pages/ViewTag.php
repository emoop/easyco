<?php

namespace App\Filament\Resources\TagResource\Pages;

use App\Filament\Resources\TagResource;
use Filament\Resources\Pages\ViewRecord;

/** Read-only. See RoleResource\Pages\ViewRole's identical note. */
class ViewTag extends ViewRecord
{
    protected static string $resource = TagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TagResource::deleteAction(),
        ];
    }
}
