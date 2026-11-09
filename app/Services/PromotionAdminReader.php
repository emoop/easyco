<?php

namespace App\Services;

use EasyCo\Promotions\Enums\PromotionScopeType;
use EasyCo\Promotions\Persistence\Eloquent\PromotionRedemptionModel;
use EasyCo\Promotions\Persistence\Eloquent\PromotionScopeModel;
use Illuminate\Support\Facades\DB;

/**
 * P5b: the READS behind the admin promotion screens (nothing here writes).
 *
 *  - stats(): how many times each listed promotion was used and how many scope rows it has — TWO grouped queries for ALL the listed promotions, whatever their number (a redemption that was released does not count: the
 *    same rule the usage limits use);
 *  - searchOptions() / labels(): the closed lists the scope form picks from (brands, categories, tags, attribute values, products), searched on the server, at most RESULT_LIMIT results per search.
 */
final class PromotionAdminReader
{
    public const RESULT_LIMIT = 20;

    /**
     * @param  list<string|int>  $promotionIds
     * @return array<string, array{used: int, scopes: int}>
     */
    public function stats(array $promotionIds): array
    {
        $out = [];

        foreach ($promotionIds as $id) {
            $out[(string) $id] = ['used' => 0, 'scopes' => 0];
        }

        if ($promotionIds === []) {
            return $out;
        }

        $used = PromotionRedemptionModel::query()
            ->whereIn('promotion_id', $promotionIds)
            ->whereNull('released_at')
            ->selectRaw('promotion_id, COUNT(*) as aggregate')
            ->groupBy('promotion_id')
            ->pluck('aggregate', 'promotion_id');

        $scopes = PromotionScopeModel::query()
            ->whereIn('promotion_id', $promotionIds)
            ->selectRaw('promotion_id, COUNT(*) as aggregate')
            ->groupBy('promotion_id')
            ->pluck('aggregate', 'promotion_id');

        foreach ($out as $id => $_) {
            $out[$id]['used'] = (int) ($used[$id] ?? 0);
            $out[$id]['scopes'] = (int) ($scopes[$id] ?? 0);
        }

        return $out;
    }

    /**
     * @return array<string, string> id => label, at most RESULT_LIMIT
     */
    public function searchOptions(PromotionScopeType $type, string $term): array
    {
        $like = '%'.addcslashes(trim($term), '%_\\').'%';

        return match ($type) {
            PromotionScopeType::BRAND => $this->pairs(DB::table('catalog_brands')->where('name', 'like', $like)->orderBy('name')->limit(self::RESULT_LIMIT)->get(['id', 'name']), 'name'),
            PromotionScopeType::CATEGORY => $this->pairs(DB::table('catalog_categories')->where('name', 'like', $like)->orderBy('name')->limit(self::RESULT_LIMIT)->get(['id', 'name']), 'name'),
            PromotionScopeType::TAG => $this->pairs(DB::table('catalog_tags')->where('name', 'like', $like)->orderBy('name')->limit(self::RESULT_LIMIT)->get(['id', 'name']), 'name'),
            PromotionScopeType::PRODUCT => $this->productPairs(DB::table('catalog_products')
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('base_sku', 'like', $like))
                ->orderBy('name')->limit(self::RESULT_LIMIT)->get(['id', 'name', 'base_sku'])),
            PromotionScopeType::ATTRIBUTE_VALUE => $this->valuePairs(DB::table('catalog_attribute_values as v')
                ->join('catalog_attribute_definitions as d', 'd.id', '=', 'v.attribute_definition_id')
                ->where(fn ($q) => $q->where('v.value', 'like', $like)->orWhere('d.name', 'like', $like))
                ->orderBy('d.name')->orderBy('v.value')->limit(self::RESULT_LIMIT)->get(['v.id as id', 'v.value as value', 'd.name as definition'])),
            default => [],
        };
    }

    /**
     * The label of ONE chosen record (the select shows it after a save or a reload); null when it no longer exists.
     */
    public function label(PromotionScopeType $type, string $id): ?string
    {
        return match ($type) {
            PromotionScopeType::BRAND => DB::table('catalog_brands')->where('id', $id)->value('name'),
            PromotionScopeType::CATEGORY => DB::table('catalog_categories')->where('id', $id)->value('name'),
            PromotionScopeType::TAG => DB::table('catalog_tags')->where('id', $id)->value('name'),
            PromotionScopeType::PRODUCT => ($p = DB::table('catalog_products')->where('id', $id)->first(['name', 'base_sku'])) ? $p->name.' ('.$p->base_sku.')' : null,
            PromotionScopeType::ATTRIBUTE_VALUE => ($v = DB::table('catalog_attribute_values as v')
                ->join('catalog_attribute_definitions as d', 'd.id', '=', 'v.attribute_definition_id')
                ->where('v.id', $id)->first(['v.value as value', 'd.name as definition'])) ? $v->definition.': '.$v->value : null,
            default => null,
        };
    }

    /** Whether the chosen record exists (a tampered id is refused, not saved). */
    public function exists(PromotionScopeType $type, string $id): bool
    {
        return $this->label($type, $id) !== null;
    }

    private function pairs($rows, string $column): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->id] = (string) $row->{$column};
        }

        return $out;
    }

    private function productPairs($rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->id] = $row->name.' ('.$row->base_sku.')';
        }

        return $out;
    }

    private function valuePairs($rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->id] = $row->definition.': '.$row->value;
        }

        return $out;
    }
}
