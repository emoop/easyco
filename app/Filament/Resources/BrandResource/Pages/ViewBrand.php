<?php

namespace App\Filament\Resources\BrandResource\Pages;

use App\Filament\Resources\BrandResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only. ViewRecord::authorizeAccess() already correctly calls
 * abort_unless(canView($record), 403) by default — see RoleResource\
 * Pages\ViewRole's identical note — no override needed here.
 */
class ViewBrand extends ViewRecord
{
    protected static string $resource = BrandResource::class;
}
