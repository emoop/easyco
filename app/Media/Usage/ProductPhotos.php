<?php

namespace App\Media\Usage;

use App\Filament\Resources\ProductResource;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Enums\ProductType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** `catalog_product_media`: the main photo, the gallery and the video of a product. An archived or soft-deleted product still holds its pictures, so it counts (labelled). */
final class ProductPhotos implements MediaUsageProvider
{
    public function counts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('catalog_product_media')->whereIn('media_id', $ids)->groupBy('media_id')->selectRaw('media_id, count(*) as n')->pluck('n', 'media_id')->map(fn ($n) => (int) $n)->all();
    }

    public function usagesOf(int $id): array
    {
        $rows = DB::table('catalog_product_media as pm')
            ->join('catalog_products as p', 'p.id', '=', 'pm.product_id')
            ->join('catalog_media as m', 'm.id', '=', 'pm.media_id')
            ->where('pm.media_id', $id)
            ->orderBy('p.id')
            ->get(['p.id', 'p.name', 'p.type', 'p.status', 'p.deleted_at', 'pm.sort_order', 'm.type as media_type', DB::raw("(select min(pm2.sort_order) from catalog_product_media pm2 join catalog_media m2 on m2.id = pm2.media_id where pm2.product_id = p.id and m2.type in ('image', 'social_image')) as first_photo")]);

        return $rows->map(function ($row): MediaUsage {
            $role = in_array($row->media_type, ['video', 'social_video'], true) ? 'video' : ((int) $row->sort_order === (int) $row->first_photo ? 'main' : 'gallery');
            $gone = $row->deleted_at !== null || $row->status === ProductStatus::ARCHIVED->value;
            $label = __('media.usage.product_'.$role, ['name' => (string) $row->name]).($gone ? ' '.__('media.usage.archived_product') : '');
            $page = $row->type === ProductType::SIMPLE->value ? 'edit' : 'edit-variable';

            return new MediaUsage('product', $label, $row->deleted_at === null ? ProductResource::getUrl($page, ['record' => $row->id]) : null);
        })->all();
    }

    public function excludeUsed(Builder $query): void
    {
        $query->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('catalog_product_media')->whereColumn('catalog_product_media.media_id', 'catalog_media.id'));
    }
}
