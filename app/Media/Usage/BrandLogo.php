<?php

namespace App\Media\Usage;

use App\Filament\Resources\BrandResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** `catalog_brands.logo_media_asset_id`. */
final class BrandLogo implements MediaUsageProvider
{
    public function counts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('catalog_brands')->whereIn('logo_media_asset_id', $ids)->groupBy('logo_media_asset_id')->selectRaw('logo_media_asset_id, count(*) as n')->pluck('n', 'logo_media_asset_id')->map(fn ($n) => (int) $n)->all();
    }

    public function usagesOf(int $id): array
    {
        return DB::table('catalog_brands')->where('logo_media_asset_id', $id)->orderBy('id')->get(['id', 'name'])
            ->map(fn ($brand): MediaUsage => new MediaUsage('brand', __('media.usage.brand_logo', ['name' => (string) $brand->name]), BrandResource::getUrl('edit', ['record' => $brand->id])))
            ->all();
    }

    public function excludeUsed(Builder $query): void
    {
        $query->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('catalog_brands')->whereColumn('catalog_brands.logo_media_asset_id', 'catalog_media.id'));
    }
}
