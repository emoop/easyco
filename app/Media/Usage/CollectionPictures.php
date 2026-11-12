<?php

namespace App\Media\Usage;

use App\Filament\Resources\CollectionResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** `catalog_collections.picture_media_asset_id` (P6e-1), as CategoryPictures does for the season's picture: ONE batched query for a page of pictures, never one per picture. */
final class CollectionPictures implements MediaUsageProvider
{
    public function counts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('catalog_collections')->whereIn('picture_media_asset_id', $ids)->groupBy('picture_media_asset_id')->selectRaw('picture_media_asset_id, count(*) as n')->pluck('n', 'picture_media_asset_id')->map(fn ($n) => (int) $n)->all();
    }

    public function usagesOf(int $id): array
    {
        return DB::table('catalog_collections')->where('picture_media_asset_id', $id)->orderBy('id')->get(['id', 'name'])
            ->map(fn ($collection): MediaUsage => new MediaUsage('collection', __('media.usage.collection', ['name' => (string) $collection->name]), CollectionResource::getUrl('edit', ['record' => $collection->id])))
            ->all();
    }

    public function excludeUsed(Builder $query): void
    {
        $query->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('catalog_collections')->whereColumn('catalog_collections.picture_media_asset_id', 'catalog_media.id'));
    }
}