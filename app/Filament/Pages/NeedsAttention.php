<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\OrderResource;
use App\NeedsAttention\NeedsAttentionItem;
use App\NeedsAttention\NeedsAttentionSource;
use App\Services\PriceDisplayFormatter;
use BackedEnum;
use EasyCo\Pricing\Money;
use EasyCo\Staff\Enums\Permission;
use Filament\Pages\Page;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Livewire\WithPagination;

/**
 * "Needs attention" (shipping-domain-design.md §7.2.20 §6): one read-only screen that lists money
 * WAITING on someone, oldest first — refunds that are owed, bank transfers whose receipts do not add
 * up. `/admin/needs-attention`, in the Sales group right after Orders.
 *
 * A LIST OF FACTS, NOT A WORKLIST. §7.2.7's own rule ("it lists facts and enforces nothing") is what
 * the whole page is allowed to do, so it has no severity, no colour, no threshold, no "overdue", no
 * badge, no notification and no action: every row is a fact that is already stored somewhere (an OWED
 * refund, an unreconciled transfer) plus the number of calendar days it has been waiting, and NOTHING
 * here writes.
 *
 * NOTHING IS STORED FOR IT EITHER (§6): a saved `needs_attention` flag would be a SECOND copy of a
 * fact, and a second copy drifts the moment the fact changes (a refund is paid out, a transfer is
 * settled) — and, worse, an unseen row keeps shouting after the merchant has fixed it. So every row
 * is DERIVED on every read, and this page keeps no row of its own anywhere.
 *
 * WHO SEES IT: Permission::ORDER_VIEW, the same read access the Orders list itself needs (every row
 * links to an order), checked in BOTH places the project always checks — the panel's own gate at
 * canAccess() and again at mount(), so a direct URL never trusts the route alone. Registered in the
 * navigation, because a merchant who is never told his refunds are waiting never pays them.
 *
 * THE SOURCES ARE TAGGED, NOT HARD-CODED (App\NeedsAttention\NeedsAttentionSource::TAG, bound in
 * AppServiceProvider): R4b adds its two cash-on-delivery sources by tagging them, and never by
 * touching this class. The tag's own array order is the order of the sections below.
 *
 * ONE PAGE PER SOURCE (§6): each section carries its own count and its own pagination, so six
 * sources in R4b never become one unwieldy list, and the cost stays what the interface promises — one
 * count plus one page read per source per render, whatever the number of rows.
 *
 * THE PAGINATION LINKS ARE PLAIN GETs, deliberately, and not Filament's own pagination component:
 * that component hard-codes its wire:key from THIS Livewire component's id, so two of them in one
 * component (exactly what two or more sections require) collide. The paginator's own URLs need no
 * Livewire at all, and they make each page of each section bookmarkable — the page is read-only, so a
 * fresh GET is an honest interaction (and one render, exactly as the sources' own contract counts).
 */
class NeedsAttention extends Page
{
    use AuthorizesViaStaffPermission;
    use WithPagination;

    /** One page of one source — the panel's own table-sized page, and enough for a merchant to clear. */
    public const PER_PAGE = 25;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected string $view = 'filament.pages.needs-attention';

    /**
     * Sales, right after Orders (10): every row of this page is about one order, and the merchant
     * reaches it while working through sales — not from Settings. See RoleResource's own docblock for
     * the group/sort convention.
     */
    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::SALES;
    }

    public static function getNavigationSort(): ?int
    {
        return 20;
    }

    protected static function accessPermission(): ?Permission
    {
        return Permission::ORDER_VIEW;
    }

    /**
     * Defined locally, not on the shared trait — see AuthorizesViaStaffPermission's own class
     * docblock for the real regression that decision avoids (Resources inherit Filament's own working
     * canAccess()).
     */
    public static function canAccess(): bool
    {
        return static::staffCanForAction(static::accessPermission());
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public static function getNavigationLabel(): string
    {
        return __('needs_attention.navigation_label');
    }

    public function getTitle(): string
    {
        return __('needs_attention.title');
    }

    /**
     * Every source's section, in the tag's own order: its heading, its total count, and one page of
     * rows — each row already carrying what the view draws (a link, the fact, the formatted amount,
     * the wait), so the view decides nothing.
     *
     * @return list<array{key: string, label: string, count: int, rows: LengthAwarePaginator<int, array<string, mixed>>}>
     */
    public function getSections(): array
    {
        $sections = [];

        foreach (app()->tagged(NeedsAttentionSource::TAG) as $source) {
            $count = $source->count();
            // This source's OWN page, from this source's own query-string key (getPage() is
            // Livewire's: it reads ?<key>=n through Paginator::resolveCurrentPage()).
            $page = max(1, (int) $this->getPage($source->key()));

            $sections[] = [
                'key' => $source->key(),
                'label' => $source->label(),
                'count' => $count,
                'rows' => new LengthAwarePaginator(
                    items: array_map(fn (NeedsAttentionItem $item): array => $this->row($item), $source->page($page, self::PER_PAGE)),
                    total: $count,
                    perPage: self::PER_PAGE,
                    currentPage: $page,
                    options: [
                        // Plain GET links back to this same path, each with its own source's page
                        // name — see this class's own docblock for why they are not Livewire calls.
                        'path' => Paginator::resolveCurrentPath(),
                        'pageName' => $source->key(),
                    ],
                ),
            ];
        }

        return $sections;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $sections = $this->getSections();

        return [
            'sections' => $sections,
            // ONE message for the whole page when there is nothing at all — one section's emptiness
            // is its own heading's business (the count is right there).
            'empty' => array_sum(array_column($sections, 'count')) === 0,
        ];
    }

    /** What the view draws for one row: a link to the order, the fact, the money, and the wait. */
    private function row(NeedsAttentionItem $item): array
    {
        return [
            'order' => $item->orderId,
            'url' => OrderResource::getUrl('view', ['record' => $item->orderId]),
            'fact' => $item->fact,
            'amount' => $this->amount($item->amount),
            'waiting' => $this->waiting($item->ageDays),
            // The day it began, kept for the cell's own title attribute: the page counts the days,
            // but a merchant reconciling a bank statement wants the date he saw.
            'started_on' => $item->startedOn,
        ];
    }

    /** Money as the rest of the panel shows it (PriceDisplayFormatter, the merchant's own symbol position). */
    private function amount(Money $amount): string
    {
        return app(PriceDisplayFormatter::class)->format($amount->decimalValue(), $amount->currency());
    }

    /**
     * How long it has waited, in whole calendar days: the ONE thing this page may say about a row
     * besides the fact itself, and the only order it expresses (§7.2.7 — no thresholds, no severity).
     * Today (0) and a negative count (a receipt a merchant dated tomorrow by mistake) both read as
     * "today" rather than as a nonsense number.
     */
    private function waiting(int $ageDays): string
    {
        return $ageDays > 0
            ? trans_choice('needs_attention.age_days', $ageDays)
            : __('needs_attention.today');
    }
}
