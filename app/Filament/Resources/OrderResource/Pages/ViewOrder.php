<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;

/**
 * ViewRecord::authorizeAccess() already correctly calls
 * abort_unless(canView($record), 403) by default (confirmed against the
 * installed source — same established note as ProductResource\Pages\
 * ViewProduct/RoleResource\Pages\ViewRole) — no override needed here.
 *
 * SIX HEADER ACTIONS, AS OF order-lifecycle-design.md §10 stage 7c-2 (four
 * since stage 7b) — the page's still-read-only D1 posture (no
 * create/edit/delete, no form) gains its first real writes:
 * Confirm/Ship/Deliver/"Mark as received"/Cancel/"Record a return", each
 * an OrderResource::*Action() factory so the object is testable without
 * Livewire (the same reason ProductResource's own actions are static
 * methods there, not inlined here — see ViewProduct's identical note).
 * Every gate, every refusal and every success notification is that
 * factory's own job; this page stays the two-line adapter it always was
 * — §8.1's own wording, `return OrderResource::orderActions();`, exactly.
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    /** the page's own scope class: the type rule below applies to this page only */
    public function getPageClasses(): array
    {
        return ['ord-view'];
    }

    /**
     * ORD-PC: ONE STEP LARGER SMALL TEXT, on this page only. Filament's small sizes are the theme variables `--text-xs` and `--text-sm` (every `text-xs` / `text-sm`
     * utility AND every component rule that sets a small size reads them); a variable set on `.ord-view` changes them for the page's content and nowhere else:
     * text-xs (0.75rem) -> text-sm (0.875rem), text-sm (0.875rem) -> text-base (1rem), the line heights of the same step. Text that is already base or larger is untouched.
     * The few inline sizes of this page (OrderResource, OrderMatchesCard) were raised by the same 0.125rem in code. Registered once per request, for this page's scope only.
     */
    public function boot(): void
    {
        // once per application instance (a request), never process-wide: a long-lived process or a test process builds a fresh application each time
        if (app()->bound('order-view.type-step')) {
            return;
        }

        app()->instance('order-view.type-step', true);

        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_START,
            static fn (): HtmlString => new HtmlString('<style>.fi-page.ord-view{--text-xs:.875rem;--text-xs--line-height:calc(1.25 / .875);--text-sm:1rem;--text-sm--line-height:1.5}</style>'),
            scopes: static::class,
        );
    }

    protected function getHeaderActions(): array
    {
        return OrderResource::orderActions();
    }
}
