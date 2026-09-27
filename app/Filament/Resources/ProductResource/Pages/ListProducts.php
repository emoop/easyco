<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Pagination\LengthAwarePaginator;

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

    /**
     * KEEP THE TABLE ON A PAGE THAT EXISTS: if the page number this request is about
     * to render is PAST the last one the current view really has, land on that last
     * page instead of rendering an empty table.
     *
     * WHY THIS IS NEEDED ON TOP OF THE VIEW SWITCH'S OWN resetPage()
     * (ProductResource::statusViewButton()): that one covers the merchant's CLICK;
     * this one covers a page number that arrives in the URL — `?page=3` is real,
     * published state (HandlesPagination.php:10-16), so a bookmark, a shared link,
     * a back-navigation, or a reload of a URL whose rows have since been archived
     * or deleted elsewhere can hand a view a page it does not have. Nothing else
     * catches it: LengthAwarePaginator happily returns an empty page for an
     * out-of-range number, so the merchant sees "no products" where the truth is
     * "you are past the end".
     *
     * WHY `rendering()` AND NOT `mount()`/`booted()`: it is Livewire's LAST hook
     * before the view renders (SupportLifecycleHooks::render() →
     * `callHook('rendering', ['view' => …, 'data' => …])` — vendor/livewire/
     * livewire/src/Features/SupportLifecycleHooks/SupportLifecycleHooks.php:142-144),
     * and the page number arrives by routes that land at DIFFERENT moments: a full
     * page load sets it while mounting, a Livewire request hydrates it, and both
     * a client-side property write and a method call (a pagination link →
     * `setPage(...)`/`previousPage(...)`, support/resources/views/components/
     * pagination/index.blade.php:31-33) are applied only AFTER the `boot`/`booted`
     * hooks have run — the `update` hook comes from HandleComponents::updateProperty()
     * (…/Mechanisms/HandleComponents/HandleComponents.php:442). Here the number is
     * final whichever way it arrived, and there is nothing to fight: the view switch
     * lands on page 1, which is in range for every view that has a row at all.
     *
     * WHY IT IS NOT AN EXTRA QUERY: getTableRecords() caches what it queries into
     * $cachedTableRecords (HasRecords.php:178) and the table's own Blade view then
     * asks for exactly those records — and gets the cache (HasRecords.php:159-161,
     * $getRecords() in tables/resources/views/index.blade.php:152) — so this call IS
     * the render's own query, run one step earlier. Only a genuinely out-of-range page
     * re-queries once, after the clamp: setPage() changes the page the paginator will
     * resolve (the resolver reads the component's own $paginators,
     * SupportPagination.php:80-90), and flushing the cache is what makes the render use
     * it instead of the discarded page.
     *
     * WHY setPage() RATHER THAN THE `$paginators` ARRAY: it is Filament's own API and
     * it resolves the TABLE's page name instead of the literal 'page'
     * (InteractsWithTable.php:267-278), so a second table on this page could not be
     * clamped by accident. Its only extra behaviour is the optional
     * `scrollToTopOfTable` dispatch, which a table has to opt into
     * (CanPaginateRecords.php:26, default false) and this one never does.
     */
    public function rendering(mixed $view = null, mixed $data = null): void
    {
        $this->clampTablePageToTheLastExistingPage();
    }

    /**
     * Clamps the table's page to the last one the CURRENT view has. A no-op on every
     * request whose page is within range, which is every request a merchant reaches
     * by clicking through the list itself.
     */
    private function clampTablePageToTheLastExistingPage(): void
    {
        // Deferred loading — a real Filament mode this table does not use — has no
        // records to inspect yet, and the request that DOES load them renders through
        // this same hook, so the clamp is not skipped here, only postponed. Asking
        // anyway would force the very query the deferral exists to avoid.
        // isTableLoaded() is the same predicate the table's own view asks
        // (CanDeferLoading.php:22-29, index.blade.php:121).
        if (! $this->isTableLoaded()) {
            return;
        }

        // No pagination, no page number to clamp (and getPage()/setPage() would have
        // nothing to mean).
        if (! $this->getTable()->isPaginated()) {
            return;
        }

        $records = $this->getTableRecords();

        // Simple and cursor paginators have no last page to clamp to.
        if (! $records instanceof LengthAwarePaginator) {
            return;
        }

        if ($records->currentPage() <= $records->lastPage()) {
            return;
        }

        $this->setPage($records->lastPage());

        $this->flushCachedTableRecords();
    }
}
