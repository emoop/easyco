<?php

namespace App\Filament\Resources\AttributeValueResource\Pages;

use App\Filament\Resources\AttributeValueResource;
use Filament\Resources\Pages\ViewRecord;

/** Read-only. See RoleResource\Pages\ViewRole's identical note. */
class ViewAttributeValue extends ViewRecord
{
    protected static string $resource = AttributeValueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AttributeValueResource::deleteAction(),
        ];
    }
}
