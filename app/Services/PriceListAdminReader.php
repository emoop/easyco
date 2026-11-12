<?php

namespace App\Services;

use App\Storefront\ReadModels\CatalogSubjectKind;
use EasyCo\Pricing\Enums\PriceListScopeType;
use EasyCo\Pricing\Persistence\Eloquent\PriceListScopeModel;
use Illuminate\Support\Facades\DB;

/**
 * P6b: the READS behind the price-list screens (nothing here writes): the one-line SCOPE SUMMARY of every listed list («Марка: Acme · Сезон: Есен-26», «Всички продукти») in a FIXED number of queries whatever the
 * number of lists — ONE read of all their scope rows and at most ONE read of names per kind.
 */
final class PriceListAdminReader
{
    /**
     * @param  list<int|string>  $listIds
     * @return array<string, array<string, list<string>>> list id => kind => the names, in the form's kind order; a list with no scope row is absent
     */
    public function scopeNames(array $listIds): array
    {
        if ($listIds === []) {
            return [];
        }

        $rows = PriceListScopeModel::query()->whereIn('price_list_id', $listIds)->orderBy('id')->get(['price_list_id', 'scope_type', 'scope_reference_id']);
        $idsByKind = [];

        foreach ($rows as $row) {
            $idsByKind[(string) $row->scope_type][(string) $row->scope_reference_id] = true;
        }

        $names = [];

        foreach ($idsByKind as $kind => $ids) {
            $names[$kind] = $this->names($kind, array_keys($ids));
        }

        $out = [];

        foreach ($rows as $row) {
            $kind = (string) $row->scope_type;
            $label = $names[$kind][(string) $row->scope_reference_id] ?? (string) $row->scope_reference_id;
            $out[(string) $row->price_list_id][$kind][] = $label;
        }

        return $out;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string> id => name
     */
    private function names(string $kind, array $ids): array
    {
        $type = PriceListScopeType::tryFrom($kind);
        $table = match ($type) {
            PriceListScopeType::BRAND => 'catalog_brands',
            PriceListScopeType::CATEGORY => 'catalog_categories',
            PriceListScopeType::TAG => 'catalog_tags',
            PriceListScopeType::SEASON => 'catalog_seasons',
            PriceListScopeType::COLLECTION => 'catalog_collections',
            PriceListScopeType::PRODUCT => 'catalog_products',
            default => null,
        };

        if ($table !== null) {
            $names = DB::table($table)->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($name): string => (string) $name)->mapWithKeys(fn ($name, $id): array => [(string) $id => $name])->all();

            // P6e-3b: a collection scope while the collections are OFF never applies: shown as a switched-off feature (not an error), the list stays as stored
            if ($type === PriceListScopeType::COLLECTION && ! CatalogSubjectKind::collectionsEnabled()) {
                $names = array_map(static fn (string $name): string => $name.' ('.__('price_lists.table.feature_off').')', $names);
            }

            return $names;
        }

        if ($type === PriceListScopeType::ATTRIBUTE_VALUE) {
            return DB::table('catalog_attribute_values as v')->join('catalog_attribute_definitions as d', 'd.id', '=', 'v.attribute_definition_id')
                ->whereIn('v.id', $ids)->get(['v.id as id', 'v.value as value', 'd.name as definition'])
                ->mapWithKeys(fn ($row): array => [(string) $row->id => $row->definition.': '.$row->value])->all();
        }

        return [];
    }
}
