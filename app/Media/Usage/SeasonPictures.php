<?php

namespace App\Media\Usage;

use App\Filament\Resources\SeasonResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** `catalog_seasons.picture_media_asset_id` (CT-1.1), as CategoryPictures does for the category's picture: ONE batched query for a page of pictures, never one per picture. */
final class SeasonPictures implements MediaUsageProvider
{
    public function counts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('catalog_seasons')->whereIn('picture_media_asset_id', $ids)->groupBy('picture_media_asset_id')->selectRaw('picture_media_asset_id, count(*) as n')->pluck('n', 'picture_media_asset_id')->map(fn ($n) => (int) $n)->all();
    }

    public function usagesOf(int $id): array
    {
        return DB::table('catalog_seasons')->where('picture_media_asset_id', $id)->orderBy('id')->get(['id', 'name'])
            ->map(fn ($season): MediaUsage => new MediaUsage('season', __('media.usage.season', ['name' => (string) $season->name]), SeasonResource::getUrl('edit', ['record' => $season->id])))
            ->all();
    }

    public function excludeUsed(Builder $query): void
    {
        $query->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('catalog_seasons')->whereColumn('catalog_seasons.picture_media_asset_id', 'catalog_media.id'));
    }
}