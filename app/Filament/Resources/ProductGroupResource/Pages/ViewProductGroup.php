<?php

namespace App\Filament\Resources\ProductGroupResource\Pages;

use App\Filament\Resources\ProductGroupResource;
use Filament\Resources\Pages\ViewRecord;

/** Read-only. See RoleResource\Pages\ViewRole's identical note. */
class ViewProductGroup extends ViewRecord
{
    protected static string $resource = ProductGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ProductGroupResource::deleteAction(),
        ];
    }
}
