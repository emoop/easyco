<?php

namespace App\Filament;

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
enum NavigationGroup implements HasLabel
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
}
