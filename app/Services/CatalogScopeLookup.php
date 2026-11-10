<?php

namespace App\Services;

use EasyCo\Pricing\Enums\PriceListScopeType;
use Illuminate\Support\Facades\DB;

/**
 * P6b: the READS behind the price-list scope pickers (nothing here writes): the six closed lists a campaign can be scoped to — brand, category, tag, SEASON, product, attribute value — searched on the server, at most
 * RESULT_LIMIT results per search, with the label of one chosen record and an existence check (a tampered id is refused, never saved). The same closed lists and the same limit as PromotionAdminReader (the promotion form),
 * plus the season, which a promotion cannot be scoped to.
 */
final class CatalogScopeLookup
{
    public const RESULT_LIMIT = 20;

    /** @var list<PriceListScopeType> the kinds a campaign can be scoped to, in the order the form shows them */
    public const KINDS = [
        PriceListScopeType::BRAND,
        PriceListScopeType::CATEGORY,
        PriceListScopeType::TAG,
        PriceListScopeType::SEASON,
        PriceListScopeType::PRODUCT,
        PriceListScopeType::ATTRIBUTE_VALUE,
    ];

    /**
     * @return array<string, string> id => label, at most RESULT_LIMIT
     */
    public function search(PriceListScopeType $kind, string $term): array
    {
        $like = '%'.addcslashes(trim($term), '%_\\').'%';

        return match ($kind) {
            PriceListScopeType::BRAND => $this->named('catalog_brands', $like),
            PriceListScopeType::CATEGORY => $this->named('catalog_categories', $like),
            PriceListScopeType::TAG => $this->named('catalog_tags', $like),
            PriceListScopeType::SEASON => $this->named('catalog_seasons', $like),
            PriceListScopeType::PRODUCT => $this->products($like),
            PriceListScopeType::ATTRIBUTE_VALUE => $this->attributeValues($like),
            default => [],
        };
    }

    /** The label of ONE chosen record; null when it no longer exists. */
    public function label(PriceListScopeType $kind, string $id): ?string
    {
        return match ($kind) {
            PriceListScopeType::BRAND => DB::table('catalog_brands')->where('id', $id)->value('name'),
            PriceListScopeType::CATEGORY => DB::table('catalog_categories')->where('id', $id)->value('name'),
            PriceListScopeType::TAG => DB::table('catalog_tags')->where('id', $id)->value('name'),
            PriceListScopeType::SEASON => DB::table('catalog_seasons')->where('id', $id)->value('name'),
            PriceListScopeType::PRODUCT => ($p = DB::table('catalog_products')->where('id', $id)->first(['name', 'base_sku'])) ? $p->name.' ('.$p->base_sku.')' : null,
            PriceListScopeType::ATTRIBUTE_VALUE => ($v = DB::table('catalog_attribute_values as v')
                ->join('catalog_attribute_definitions as d', 'd.id', '=', 'v.attribute_definition_id')
                ->where('v.id', $id)->first(['v.value as value', 'd.name as definition'])) ? $v->definition.': '.$v->value : null,
            default => null,
        };
    }

    public function exists(PriceListScopeType $kind, string $id): bool
    {
        return $this->label($kind, $id) !== null;
    }

    /** @return array<string, string> */
    private function named(string $table, string $like): array
    {
        $out = [];

        foreach (DB::table($table)->where('name', 'like', $like)->orderBy('name')->limit(self::RESULT_LIMIT)->get(['id', 'name']) as $row) {
            $out[(string) $row->id] = (string) $row->name;
        }

        return $out;
    }

    /** @return array<string, string> */
    private function products(string $like): array
    {
        $out = [];

        foreach (DB::table('catalog_products')->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('base_sku', 'like', $like))->orderBy('name')->limit(self::RESULT_LIMIT)->get(['id', 'name', 'base_sku']) as $row) {
            $out[(string) $row->id] = $row->name.' ('.$row->base_sku.')';
        }

        return $out;
    }

    /** @return array<string, string> */
    private function attributeValues(string $like): array
    {
        $out = [];

        foreach (DB::table('catalog_attribute_values as v')
            ->join('catalog_attribute_definitions as d', 'd.id', '=', 'v.attribute_definition_id')
            ->where(fn ($q) => $q->where('v.value', 'like', $like)->orWhere('d.name', 'like', $like))
            ->orderBy('d.name')->orderBy('v.value')->limit(self::RESULT_LIMIT)
            ->get(['v.id as id', 'v.value as value', 'd.name as definition']) as $row) {
            $out[(string) $row->id] = $row->definition.': '.$row->value;
        }

        return $out;
    }
}
