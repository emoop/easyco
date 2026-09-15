<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only. ViewRecord::authorizeAccess() already correctly calls
 * abort_unless(canView($record), 403) by default — see RoleResource\
 * Pages\ViewRole's identical note — no override needed here. No
 * deleteAction() in header actions — Product has no delete() domain
 * method, by design.
 */
class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;
}
