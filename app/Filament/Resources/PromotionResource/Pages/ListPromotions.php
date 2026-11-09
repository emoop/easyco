<?php

namespace App\Filament\Resources\PromotionResource\Pages;

use App\Filament\Resources\PromotionResource;
use App\Filament\Support\HelpLink;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

/** The promo codes. No public method of its own: every write goes through PromotionWriter from the create / edit pages and the status action. */
class ListPromotions extends ListRecords
{
    protected static string $resource = PromotionResource::class;

    /** The help link (new tab): what a promo code is, the scope and the public code. */
    public function getSubheading(): string|Htmlable|null
    {
        return HelpLink::groupHtml(['promo_overview', 'promo_scope', 'promo_public'], 'promotions');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('promotions.actions.create')),
        ];
    }
}
