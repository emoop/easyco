<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /**
     * Filament v5.8.1's ListRecords::authorizeAccess() is still a no-op
     * by default, so canViewAny() alone would only hide the navigation
     * link, not actually block a direct visit to this page's URL — same
     * established project convention as ListBrands/ListRoles/
     * ListProductGroups (see any of those pages' own identical
     * docblock).
     */
    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    /**
     * No header actions — D1: strictly read-only, no Create page exists
     * at all (see OrderResource::getPages()), so there is nothing a
     * header action here could ever open.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
