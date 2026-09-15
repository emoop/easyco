<?php

namespace App\Filament\Resources\ProductGroupResource\Pages;

use App\Filament\Resources\ProductGroupResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only. See RoleResource\Pages\ViewRole's identical note. No
 * deleteAction() in header actions — ProductGroup has no delete()
 * domain method or repository method, by design.
 */
class ViewProductGroup extends ViewRecord
{
    protected static string $resource = ProductGroupResource::class;
}
