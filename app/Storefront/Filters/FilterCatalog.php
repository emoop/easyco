<?php

namespace App\Storefront\Filters;

use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
use EasyCo\Catalog\Persistence\Eloquent\AttributeValueModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * CT-3: the shop's filters as data: every attribute marked «Филтрируем» (a closed list: select | multiselect), in the order of the admin (by id), with its look and its values in the admin's order
 * (`sort_order`, then id) and their colour. Read in ONE query and kept in the application cache (an empty shop-list is cached too, so a category of a shop with no filters pays no query), forgotten
 * when an attribute or a value is saved or deleted (AppServiceProvider) and at the latest after TTL seconds.
 *
 * The identifiers of the URL are the numeric ids of the attribute and of the value: stable (a rename never breaks a link), URL-safe and unique; the values have no slug of their own.
 *
 * @phpstan-type FilterValue array{id: int, label: string, colour: ?string}
 * @phpstan-type Filter array{id: int, name: string, display: string, values: list<FilterValue>}
 */
final class FilterCatalog
{
    public const CACHE_KEY = 'storefront.filters.catalog';

    public const TTL = 600;

    /** @var list<Filter>|null memoised for the request */
    private ?array $filters = null;

    /** @return list<Filter> */
    public function all(): array
    {
        return $this->filters ??= Cache::remember(self::CACHE_KEY, self::TTL, fn (): array => $this->load());
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return list<Filter> */
    private function load(): array
    {
        $rows = DB::table('catalog_attribute_definitions as d')
            ->join('catalog_attribute_values as v', 'v.attribute_definition_id', '=', 'd.id')
            ->where('d.is_filterable', true)
            ->whereIn('d.type', ['select', 'multiselect'])
            ->orderBy('d.id')->orderBy('v.sort_order')->orderBy('v.id')
            ->get(['d.id as attribute_id', 'd.name', 'd.filter_display', 'v.id as value_id', 'v.value', 'v.colour']);

        $filters = [];

        foreach ($rows as $row) {
            $id = (int) $row->attribute_id;
            $filters[$id] ??= ['id' => $id, 'name' => (string) $row->name, 'display' => in_array($row->filter_display, ['list', 'links', 'swatches'], true) ? (string) $row->filter_display : 'list', 'values' => []];
            $colour = is_string($row->colour) && preg_match('/\A#[0-9a-f]{6}\z/', strtolower($row->colour)) === 1 ? strtolower($row->colour) : null;
            $filters[$id]['values'][] = ['id' => (int) $row->value_id, 'label' => (string) $row->value, 'colour' => $colour];
        }

        return array_values($filters);
    }

    /** Registers the forgetting on every change of an attribute or of a value (called once, from the service provider). */
    public static function listen(): void
    {
        foreach ([AttributeDefinitionModel::class, AttributeValueModel::class] as $model) {
            foreach (['saved', 'deleted'] as $event) {
                $model::$event(static fn () => self::forget());
            }
        }
    }
}
