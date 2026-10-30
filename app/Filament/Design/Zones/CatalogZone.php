<?php

namespace App\Filament\Design\Zones;

use App\Storefront\PageDesign\PageDesign;
use App\Storefront\Reader\CategoryIndex;

/**
 * CD-1a: «Каталог» — the columns (desktop and phone) and the number of products per page of the product listing (the category page). The tablet is automatic (min(desktop, 3)). The layout / side-panel
 * controls arrive with the filters (CT-3). The preview is the shop's first category page that shows products (the live home page when there is none).
 */
final class CatalogZone extends PageDesignZone
{
    protected function section(): string
    {
        return PageDesign::CATALOG;
    }

    protected function groups(): array
    {
        return [
            ['heading' => 'design.page_design.groups.catalog', 'fields' => ['cols', 'cols_phone', 'per_page']],
        ];
    }

    public function schemaView(): string
    {
        return 'filament.pages.design.partials.schema-catalog';
    }

    public function previewUrl(): string
    {
        $first = app(CategoryIndex::class)->tree()[0] ?? null;

        return ($first !== null ? $first->url : route('storefront.home', [], false)).'?ds='.time();
    }
}
