<?php

namespace App\Filament\Design\Zones;

use App\Storefront\PageDesign\PageDesign;
use App\Storefront\PageDesign\PageDesignStore;
use App\Http\Controllers\Storefront\DesignPreviewController;

/**
 * CD-1a: «Каталог» — the columns (desktop and phone) and the number of products per page of the product listing (the category page). The tablet is automatic (min(desktop, 3)). The layout / side-panel
 * controls arrive with the filters (CT-3). P1: the filters themselves (their order, «Показвай» and the kind of brand / season / tag) are the «Сайдбар» zone's; the three kinds stay in this section's JSON and this form carries them through untouched. The preview is the shop's first category page that shows products (the live home page when there is none).
 */
final class CatalogZone extends PageDesignZone
{
    /** The «Каталог» fields that belong to the «Сайдбар» zone's form (the kind of the three built-in filters). */
    private const SIDEBAR_KINDS = ['filterBrand', 'filterSeason', 'filterTag'];

    protected function section(): string
    {
        return PageDesign::CATALOG;
    }

    protected function groups(): array
    {
        return [
            ['heading' => 'design.page_design.groups.catalog', 'fields' => ['cols', 'cols_phone', 'per_page', 'layout', 'side', 'sideWidth', 'cardName']],
        ];
    }

    /** P1: the panel no longer shows the three built-in kinds (they belong to «Сайдбар»), so the working copy keeps the values it already has for them. */
    public function applyProps(array $tree, string $layout, ?array $selected, array $raw): array
    {
        $kept = array_intersect_key($tree['values'] ?? [], array_flip(self::SIDEBAR_KINDS));
        $tree = parent::applyProps($tree, $layout, $selected, $raw);
        $tree['values'] = array_replace($tree['values'], $kept);

        return $tree;
    }

    /** P1: a working copy made before «Сайдбар» published new kinds must not write the old ones back: the three kinds are always the PUBLISHED ones. */
    public function publish(array $stored): void
    {
        parent::publish(array_replace($stored, array_intersect_key(PageDesignStore::values(PageDesign::CATALOG), array_flip(self::SIDEBAR_KINDS))));
    }

    public function schemaView(): string
    {
        return 'filament.pages.design.partials.schema-catalog';
    }

    /** CD-1a.1: the preview shows the APPLIED working copy (a signed preview; the draft never reaches a public request). */
    public function previewNote(): ?string
    {
        return (string) __('design.preview.applied_note');
    }

    public function previewUrl(): string
    {
        return DesignPreviewController::previewUrl(PageDesign::CATALOG);
    }
}
