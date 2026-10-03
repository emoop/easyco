<?php

namespace App\Filament;

use Filament\Navigation\NavigationGroup as FilamentNavigationGroup;
use Filament\Support\Contracts\Collapsible;
use Filament\Support\Contracts\HasLabel;

/**
 * The admin sidebar's top-level groups, in the exact order they should
 * render — admin-panel-design.md's own stated structure (this task).
 *
 * Deliberately an enum case's identity, NOT a raw translated string,
 * returned from every Resource/Page's getNavigationGroup(): Filament's
 * real NavigationManager::get() (confirmed directly against the
 * installed v5.8.1 source) orders groups by matching them against a
 * panel-registered groups list, or — when none is registered, as here
 * — by a UnitEnum case's own array_search() position among
 * static::cases(), which is locale-independent and immune to any
 * individual item's own getNavigationSort() value. A raw translated
 * string was tried first and found to order groups by accident
 * (whichever item anywhere in the sidebar happens to have the lowest
 * sort value determines which group renders first) — this enum is the
 * real fix, not a workaround.
 *
 * Add future groups (Клиенти, CMS, Доклади, Маркетинг, Shipping) as new
 * cases here, in the desired render order — that's the whole mechanism;
 * nothing else needs to change.
 */
enum NavigationGroup implements Collapsible, HasLabel
{
    case CATALOG;
    case SALES;
    case ADMIN;

    public function getLabel(): string
    {
        return match ($this) {
            self::CATALOG => __('navigation.groups.catalog'),
            self::SALES => __('navigation.groups.sales'),
            self::ADMIN => __('navigation.groups.admin'),
        };
    }

    /**
     * Every group renders COLLAPSED by default — the sidebar's top-level
     * headings open as closed disclosures, so its items stay hidden until a
     * merchant clicks a group. Consumed by navigationGroups() below.
     */
    public function isCollapsed(): bool
    {
        return true;
    }

    /**
     * Kept collapsible so a merchant can still toggle a group open/closed;
     * this mirrors Filament's own default (hasCollapsibleNavigationGroups()
     * is true unless a panel turns it off). Returning true here only makes
     * that explicit, it does not change the rendered chevron/toggle.
     */
    public function isCollapsible(): bool
    {
        return true;
    }

    /**
     * Builds this enum's cases as Filament NavigationGroups for the panel's
     * own ->navigationGroups() registration (see AdminPanelProvider).
     *
     * The label is passed as a LAZY closure — `$case->getLabel(...)` — not
     * the eager string Filament's own NavigationGroup::fromEnum() would
     * freeze onto the group at panel-boot time. Panel registration happens
     * during service-provider boot, i.e. BEFORE ApplyStoreLocale's middleware
     * runs, so an eager label resolves once in the default locale and then
     * stays there for every request — observed in practice as the sidebar
     * showing "Catalog"/"Sales"/"Admin" under a Bulgarian site.locale. A
     * closure is instead evaluated by NavigationGroup::getLabel() at render
     * time, in the merchant's configured locale, which is exactly how the
     * unregistered path already translated correctly.
     *
     * Keys are the enum case names so NavigationManager::get() matches each
     * group to the enum case every item's own getNavigationGroup() returns;
     * render order is unaffected, since NavigationManager sorts by the enum
     * case's position among cases(), not by registration order.
     *
     * @return array<string, FilamentNavigationGroup>
     */
    public static function navigationGroups(): array
    {
        $groups = [];

        foreach (self::cases() as $case) {
            $groups[$case->name] = FilamentNavigationGroup::make($case->getLabel(...))
                ->collapsed($case->isCollapsed())
                ->collapsible($case->isCollapsible());
        }

        return $groups;
    }
}
