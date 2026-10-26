<?php

namespace App\Media\Usage;

use App\Filament\Resources\AttributeDefinitionResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** `catalog_attribute_values.picture_media_asset_id` (CT-1: a season, a colour ...): ONE batched query for a page of pictures; the label names the value and its attribute and links to the attribute's edit page. */
final class AttributeValuePictures implements MediaUsageProvider
{
    public function counts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('catalog_attribute_values')->whereIn('picture_media_asset_id', $ids)->groupBy('picture_media_asset_id')->selectRaw('picture_media_asset_id, count(*) as n')->pluck('n', 'picture_media_asset_id')->map(fn ($n) => (int) $n)->all();
    }

    public function usagesOf(int $id): array
    {
        return DB::table('catalog_attribute_values')
            ->join('catalog_attribute_definitions', 'catalog_attribute_definitions.id', '=', 'catalog_attribute_values.attribute_definition_id')
            ->where('catalog_attribute_values.picture_media_asset_id', $id)
            ->orderBy('catalog_attribute_values.id')
            ->get(['catalog_attribute_values.value as value', 'catalog_attribute_definitions.id as definition_id', 'catalog_attribute_definitions.name as attribute'])
            ->map(fn ($row): MediaUsage => new MediaUsage('attribute_value', __('media.usage.attribute_value', ['value' => (string) $row->value, 'attribute' => (string) $row->attribute]), AttributeDefinitionResource::getUrl('edit', ['record' => $row->definition_id])))
            ->all();
    }

    public function excludeUsed(Builder $query): void
    {
        $query->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('catalog_attribute_values')->whereColumn('catalog_attribute_values.picture_media_asset_id', 'catalog_media.id'));
    }
}
