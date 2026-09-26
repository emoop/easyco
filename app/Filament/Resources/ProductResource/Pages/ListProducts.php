<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    /**
     * WHICH STATUS VIEW THE LIST IS SHOWING — the four toolbar buttons' (D1) whole
     * state, and the reason it lives HERE rather than in the Resource's table: the
     * choice has to end up in the URL query string as `?status=…` so a reload and a
     * back-navigation return to the same view, and a Livewire property is what
     * carries it there.
     *
     * ONE OF ProductResource::STATUS_VIEWS, DEFAULT Active; anything else (a crafted
     * value, a stale bookmark, an old link) is treated as the default by
     * ProductResource::statusViewFrom() — the ONE reader, used by the table's own
     * query and by every status-dependent action's visibility.
     *
     * #[Url] IS LIVEWIRE'S OWN MECHANISM, verified against the installed source
     * rather than assumed:
     * Livewire\Features\SupportQueryString\BaseUrl::mount() →
     * setPropertyFromQueryString() → getFromUrlQueryString(), which for a
     * non-Livewire request (a FULL PAGE LOAD) reads
     * `data_get(request()->query(), 'status', …)` and sets this very property — so
     * `?status=archived` genuinely restores the view on reload, and later switches
     * update the URL through the attribute's own `url` effect.
     *
     * history: false (the attribute's default) REPLACES the URL rather than pushing
     * a history entry: the parameter is present at all times, so a back-navigation
     * from a product page still lands on the same view, while switching views four
     * times does not leave four entries in the browser's back stack.
     */
    #[\Livewire\Attributes\Url(as: 'status')]
    public string $statusView = ProductResource::STATUS_VIEW_DEFAULT;

    /**
     * See RoleResource\Pages\ListRoles's identical override — Filament
     * v5.8.1's ListRecords::authorizeAccess() is still a no-op by
     * default, so canViewAny() alone would only hide the navigation
     * link, not actually block a direct visit to this page's URL.
     */
    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    /**
     * Two explicit entry points, per admin-panel-design.md §13.1: a
     * SIMPLE product (the existing single-page flow) and a VARIABLE
     * product (the new wizard, CreateVariableProduct — Step A only,
     * Axes/Variations grid/price/stock are separate, later steps).
     * CreateAction::make()'s own ->label() is overridden here rather
     * than left at its Filament default ("Create Product") — confirmed
     * real and overridable against the installed v5.8.1 source
     * (CreateAction::setUp() only sets a default via ->label(), which
     * a later ->label() call on the same fluent chain replaces).
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('products.create_simple_action')),
            Action::make('create_variable')
                ->label(__('products.create_variable_action'))
                ->url(fn (): string => ProductResource::getUrl('create-variable'))
                ->visible(fn (): bool => ProductResource::canCreate()),
        ];
    }
}
