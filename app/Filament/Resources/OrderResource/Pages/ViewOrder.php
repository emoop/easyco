<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only. ViewRecord::authorizeAccess() already correctly calls
 * abort_unless(canView($record), 403) by default (confirmed against the
 * installed source — same established note as ProductResource\Pages\
 * ViewProduct/RoleResource\Pages\ViewRole) — no override needed here.
 * No header actions at all — D1: strictly read-only, nothing here to
 * duplicate, edit, or act on.
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
