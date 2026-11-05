<?php

namespace App\Storefront\Filters;

use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Sidebar\SidebarFilters;
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

    /**
     * P2: the filters that are HIDDEN until the merchant switches them on in «Сайдбар»: a list that does not mention one of them (a fresh shop, a list saved before the filter existed) gets it appended HIDDEN, every other
     * missing filter is appended shown. The per-filter default lives here, not in the sidebar store.
     */
    public const HIDDEN_BY_DEFAULT = ['featured'];

    /** @var array{attributes: list<array<string, mixed>>, builtin: array<string, list<array{id: int, label: string, colour: null}>>}|null memoised for the request */
    private ?array $data = null;

    /**
     * P1: the filters the visitor can use, in the order of the «Сайдбар» list, without the hidden ones. A hidden filter is not here at all: it is not printed (side panel, top bar, chips), costs no facet query and
     * its key in a URL is the storefront's 404 (FilterSelection knows only these). Without a saved list: brand, season, the attributes, tag, a built-in shown unless its older «Каталог» choice is `off`.
     *
     * @return list<Filter>
     */
    public function all(): array
    {
        $this->load();

        $appearance = app(StorefrontAppearance::class);
        $kinds = $appearance->catalogFilterKinds();
        $byKey = [];

        foreach (SidebarFilters::BUILTIN as $kind) {
            $values = $this->data['builtin'][$kind] ?? [];

            $values === [] || $byKey[$kind] = ['id' => $kind, 'name' => (string) __('storefront.filter_'.$kind), 'display' => SidebarFilters::kindToPrint($kinds[$kind] ?? null), 'values' => $values];
        }

        foreach ($this->data['attributes'] as $attribute) {
            $byKey[(string) $attribute['id']] = $attribute;
        }

        // P2: «Препоръчани» is a SWITCH (`display: switch`, no values): the page prints one control that toggles `featured=1`
        $byKey['featured'] = ['id' => 'featured', 'name' => (string) __('storefront.filter_featured'), 'display' => 'switch', 'values' => []];

        $out = [];

        foreach (SidebarFilters::effective($appearance->sidebarFilters(), $this->knownKeys(), $kinds, self::HIDDEN_BY_DEFAULT) as $row) {
            $row['show'] && isset($byKey[$row['key']]) && $out[] = $byKey[$row['key']];
        }

        return $out;
    }

    /**
     * P1: every filter key the shop knows now, in the default order (brand, season, the attributes by id, tag) with its admin name: what the «Сайдбар» list may hold. Reads the cached data (no query when warm).
     *
     * @return array<string, string> key => name
     */
    public function names(): array
    {
        $this->load();
        $names = [];

        foreach (['brand', 'season'] as $kind) {
            $names[$kind] = (string) __('storefront.filter_'.$kind);
        }

        foreach ($this->data['attributes'] as $attribute) {
            $names[(string) $attribute['id']] = (string) $attribute['name'];
        }

        $names['tag'] = (string) __('storefront.filter_tag');
        $names['featured'] = (string) __('storefront.filter_featured');

        return $names;
    }

    /**
     * P2: is a SWITCH («Препоръчани») shown by the merchant? Read from the saved list alone (the appearance: no query, no filter data): a list that does not mention it has it hidden.
     */
    public function hasShownSwitch(): bool
    {
        foreach (app(StorefrontAppearance::class)->sidebarFilters() ?? [] as $row) {
            if (is_array($row) && in_array($row['key'] ?? null, SidebarFilters::SWITCHES, true) && ($row['show'] ?? true) !== false) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the keys that are hidden until the merchant switches them on (see HIDDEN_BY_DEFAULT) */
    public function hiddenByDefault(): array
    {
        return self::HIDDEN_BY_DEFAULT;
    }

    /** @return list<string> */
    public function knownKeys(): array
    {
        return array_map('strval', array_keys($this->names()));
    }

    private function load(): void
    {
        $this->data ??= Cache::remember(self::CACHE_KEY, self::TTL, fn (): array => ['attributes' => $this->loadAttributes(), 'builtin' => $this->loadBuiltins()]);
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
