<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /**
     * WHICH STATUS VIEW THE LIST IS SHOWING — byte-for-byte
     * ListProducts::$statusView's own shape (see that property's own
     * docblock for the full #[Url] mechanism, verified against the
     * installed Livewire source there, not repeated here): one of
     * OrderResource::STATUS_VIEWS, default 'all' (OrderResource::
     * STATUS_VIEW_DEFAULT), self-healing via OrderResource::statusViewFrom().
     */
    #[\Livewire\Attributes\Url(as: 'status')]
    public string $statusView = OrderResource::STATUS_VIEW_DEFAULT;

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
