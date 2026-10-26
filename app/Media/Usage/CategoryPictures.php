<?php

namespace App\Media\Usage;

use App\Filament\Resources\CategoryResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** `catalog_categories.picture_media_asset_id` (CT-1), as BrandLogo does for the brand's logo: ONE batched query for a page of pictures, never one per picture. */
final class CategoryPictures implements MediaUsageProvider
{
    public function counts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('catalog_categories')->whereIn('picture_media_asset_id', $ids)->groupBy('picture_media_asset_id')->selectRaw('picture_media_asset_id, count(*) as n')->pluck('n', 'picture_media_asset_id')->map(fn ($n) => (int) $n)->all();
    }

    public function usagesOf(int $id): array
    {
        return DB::table('catalog_categories')->where('picture_media_asset_id', $id)->orderBy('id')->get(['id', 'name'])
            ->map(fn ($category): MediaUsage => new MediaUsage('category', __('media.usage.category', ['name' => (string) $category->name]), CategoryResource::getUrl('edit', ['record' => $category->id])))
            ->all();
    }

    public function excludeUsed(Builder $query): void
    {
        $query->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('catalog_categories')->whereColumn('catalog_categories.picture_media_asset_id', 'catalog_media.id'));
    }
}
