<?php

namespace App\Storefront\Filters;

use App\Storefront\Appearance\StorefrontAppearance;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
use EasyCo\Catalog\Persistence\Eloquent\AttributeValueModel;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Catalog\Persistence\Eloquent\SeasonModel;
use EasyCo\Catalog\Persistence\Eloquent\TagModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * CT-3 / CT-4: the shop's filters as data. The ATTRIBUTE filters: every attribute marked «Филтрируем» (a closed list: select | multiselect), in the order of the admin (by id), with its look and its values in
 * the admin's order (`sort_order`, then id) and their colour. The BUILT-IN filters (CT-4): brand, season and tag, each with all its values by name, then id. The attributes are read in ONE query and the three
 * built-ins in ONE more (a `UNION ALL`); both are kept together in the application cache (an empty shop-list is cached too, so a category of a shop with no filters pays no query), forgotten when an attribute,
 * a value, a brand, a season or a tag is saved or deleted (AppServiceProvider) and at the latest after TTL seconds. A cold read costs these two queries, a warm one none.
 *
 * What the visitor sees is composed per request from that cached data and the PUBLISHED «Каталог» choices (StorefrontAppearance::catalogFilterKinds): a built-in that is switched off, or that has no values at
 * all, is not in the list (so its key is a 404 and it costs no facet query). The ORDER is: brand, season, the attributes, tag.
 *
 * The identifiers of the URL are the numeric ids of the attribute and of the value (stable, URL-safe and unique; the values have no slug of their own); the built-ins' keys are the reserved words `brand`,
 * `season` and `tag` (see FilterConstraint::BUILTIN) with the numeric ids of the brand / season / tag as values.
 *
 * @phpstan-type FilterValue array{id: int, label: string, colour: ?string}
 * @phpstan-type Filter array{id: int|string, name: string, display: string, values: list<FilterValue>}
 */
final class FilterCatalog
{
    public const CACHE_KEY = 'storefront.filters.catalog.v2';

    public const TTL = 600;

    /** @var array{attributes: list<array<string, mixed>>, builtin: array<string, list<array{id: int, label: string, colour: null}>>}|null memoised for the request */
    private ?array $data = null;

    /** @return list<Filter> */
    public function all(): array
    {
        $this->data ??= Cache::remember(self::CACHE_KEY, self::TTL, fn (): array => ['attributes' => $this->loadAttributes(), 'builtin' => $this->loadBuiltins()]);

        $kinds = app(StorefrontAppearance::class)->catalogFilterKinds();
        $builtin = function (string $kind) use ($kinds): array {
            $values = $this->data['builtin'][$kind] ?? [];

            return ($kinds[$kind] ?? 'off') === 'off' || $values === [] ? [] : [['id' => $kind, 'name' => (string) __('storefront.filter_'.$kind), 'display' => $kinds[$kind], 'values' => $values]];
        };

        return [...$builtin('brand'), ...$builtin('season'), ...$this->data['attributes'], ...$builtin('tag')];
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return list<array<string, mixed>> */
    private function loadAttributes(): array
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

    /**
     * CT-4: ALL the brands, seasons and tags in ONE query (a UNION ALL of three small tables), each kind by name then id. They are not narrowed to those that have products here: which of them GIVE products is
     * the facet's job (one query per shown filter, per request).
     *
     * @return array<string, list<array{id: int, label: string, colour: null}>>
     */
    private function loadBuiltins(): array
    {
        $union = DB::table('catalog_brands')->selectRaw("'brand' as kind, id, name");
        $union->unionAll(DB::table('catalog_seasons')->selectRaw("'season' as kind, id, name"));
        $union->unionAll(DB::table('catalog_tags')->selectRaw("'tag' as kind, id, name"));

        $rows = DB::query()->fromSub($union, 'b')->orderBy('name')->orderBy('id')->get(['kind', 'id', 'name']);
        $out = ['brand' => [], 'season' => [], 'tag' => []];

        foreach ($rows as $row) {
            $out[(string) $row->kind][] = ['id' => (int) $row->id, 'label' => (string) $row->name, 'colour' => null];
        }

        return $out;
    }

    /** Registers the forgetting on every change of an attribute, a value, a brand, a season or a tag (called once, from the service provider). */
    public static function listen(): void
    {
        foreach ([AttributeDefinitionModel::class, AttributeValueModel::class, BrandModel::class, SeasonModel::class, TagModel::class] as $model) {
            foreach (['saved', 'deleted'] as $event) {
                $model::$event(static fn () => self::forget());
            }
        }
    }
}
