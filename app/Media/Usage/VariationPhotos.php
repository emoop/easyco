<?php

namespace App\Media\Usage;

use App\Filament\Resources\ProductResource;
use EasyCo\Catalog\Enums\ProductType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** `catalog_variation_media`: the photos of one variation. */
final class VariationPhotos implements MediaUsageProvider
{
    public function counts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('catalog_variation_media')->whereIn('media_id', $ids)->groupBy('media_id')->selectRaw('media_id, count(*) as n')->pluck('n', 'media_id')->map(fn ($n) => (int) $n)->all();
    }

    public function usagesOf(int $id): array
    {
        $rows = DB::table('catalog_variation_media as vm')
            ->join('catalog_variations as v', 'v.id', '=', 'vm.variation_id')
            ->join('catalog_products as p', 'p.id', '=', 'v.product_id')
            ->where('vm.media_id', $id)
            ->orderBy('v.id')
            ->get(['v.id as variation_id', 'p.id', 'p.name', 'p.type', 'p.deleted_at', 'v.deleted_at as variation_deleted_at']);

        $combinations = $rows->isEmpty() ? collect() : DB::table('catalog_variation_attribute_values as va')
            ->join('catalog_attribute_definitions as d', 'd.id', '=', 'va.attribute_definition_id')
            ->join('catalog_attribute_values as av', 'av.id', '=', 'va.attribute_value_id')
            ->whereIn('va.variation_id', $rows->pluck('variation_id')->all())
            ->orderBy('va.id')
            ->get(['va.variation_id', 'd.name as definition', 'av.value'])
            ->groupBy('variation_id')
            ->map(fn ($parts) => $parts->map(fn ($part) => $part->definition.': '.$part->value)->implode(', '));

        return $rows->map(function ($row) use ($combinations): MediaUsage {
            $combination = (string) ($combinations[$row->variation_id] ?? '');
            $gone = $row->deleted_at !== null || $row->variation_deleted_at !== null;
            $label = __('media.usage.variation', ['name' => $row->name.($combination !== '' ? ' — '.$combination : '')]).($gone ? ' '.__('media.usage.archived_product') : '');
            $page = $row->type === ProductType::SIMPLE->value ? 'edit' : 'edit-variable';

            return new MediaUsage('variation', $label, $row->deleted_at === null ? ProductResource::getUrl($page, ['record' => $row->id]) : null);
        })->all();
    }

    public function excludeUsed(Builder $query): void
    {
        $query->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('catalog_variation_media')->whereColumn('catalog_variation_media.media_id', 'catalog_media.id'));
    }
}
