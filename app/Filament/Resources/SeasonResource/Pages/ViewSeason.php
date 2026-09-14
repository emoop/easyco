<?php

namespace App\Filament\Resources\SeasonResource\Pages;

use App\Filament\Resources\SeasonResource;
use Filament\Resources\Pages\ViewRecord;

/** Read-only. See RoleResource\Pages\ViewRole's identical note. */
class ViewSeason extends ViewRecord
{
    protected static string $resource = SeasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SeasonResource::deleteAction(),
        ];
    }
}
