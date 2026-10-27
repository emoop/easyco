<?php

namespace App\Filament\Design\Zones;

use App\Storefront\PageDesign\PageDesign;

/**
 * GL-2: «Страница и карти» — the page's side padding and the listing cards' border and corners (separately for a desktop and a phone). The preview is the live home page.
 */
final class PageCardsZone extends PageDesignZone
{
    protected function section(): string
    {
        return PageDesign::PAGE;
    }

    protected function groups(): array
    {
        return [
            ['heading' => 'design.page_design.groups.pad', 'fields' => ['pad_min', 'pad_vw', 'pad_max']],
            ['heading' => 'design.page_design.groups.cards', 'pairs' => [
                'design.page_design.devices.desktop' => ['card_border', 'card_radius'],
                'design.page_design.devices.mobile' => ['card_border_m', 'card_radius_m'],
            ]],
        ];
    }

    /** The live home page; a changing query makes the frame fetch it again after «Запази». */
    public function previewUrl(): string
    {
        return route('storefront.home', [], false).'?ds='.time();
    }
}
