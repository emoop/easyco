<?php

namespace App\Filament\Design\Zones;

use App\Storefront\PageDesign\PageDesign;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;

/**
 * GL-2: «Продуктова страница» — the gallery's modes, width, odd last photo and hover zoom, the gaps and corners of the gallery and the gap and stickiness of the right column. The preview is the first
 * product the shop shows (the live home page when there is none).
 */
final class ProductPageZone extends PageDesignZone
{
    protected function section(): string
    {
        return PageDesign::PRODUCT;
    }

    protected function groups(): array
    {
        return [
            ['heading' => 'design.page_design.groups.gallery', 'fields' => ['gallery_desktop_mode', 'gallery_mobile_mode', 'gallery_width', 'gallery_odd_last', 'gallery_hover_zoom']],
            ['heading' => 'design.page_design.groups.spacing', 'fields' => ['gal_gap', 'gal_radius', 'col_gap']],
            ['heading' => 'design.page_design.groups.column', 'fields' => ['sticky']],
        ];
    }

    public function previewUrl(): string
    {
        $slug = ProductModel::query()->where('status', 'active')->where('catalog_visibility', 'visible')->orderBy('id')->value('slug');

        return (is_string($slug) && $slug !== '' ? route('storefront.product', ['slug' => $slug], false) : route('storefront.home', [], false)).'?ds='.time();
    }
}
