<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only. Unlike ListRecords, ViewRecord::authorizeAccess() already
 * correctly calls abort_unless(canView($record), 403) by default
 * (confirmed directly against the installed v5.8.1 source) — no
 * override needed here, unlike ListRoles'/EditRole's own gaps.
 */
class ViewRole extends ViewRecord
{
    protected static string $resource = RoleResource::class;
}
