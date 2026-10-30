<?php

namespace App\Storefront\Filters;

use App\Storefront\ReadModels\ListingSort;
use App\Storefront\Support\PaginationLinks;

/**
 * CT-3 / CD-1b: what the filters form and the sort control print, as plain data. For every filter of the shop (the admin's order) the values to SHOW: those that give products together with the OTHER
 * filters' choices (the facet ids, one query per filter) plus every selected value (kept visible so the visitor can remove it, even when it now gives nothing). A filter with fewer than two values to
 * show and nothing selected is not shown. The chips (the chosen values) are links that remove ONE choice: the other filters, `per_page` and `sort` kept, `page` dropped (a new choice starts at page 1),
 * the filters in the canonical order, so one selection is one URL. The values themselves are checkboxes of ONE GET form (CD-1b); their `selected` flag is the `checked` state.
 */
final class FilterView
{
    /**
     * @param  list<array{id: int, name: string, display: string, values: list<array{id: int, label: string, colour: ?string}>}>  $catalog
     * @param  array<int, list<int>>  $facets  attribute id => the value ids that give products
     * @return array{groups: list<array<string, mixed>>, chips: list<array{label: string, url: string}>, clear: ?string, active: int}|null null = nothing to print
     */
    public static function build(array $catalog, FilterSelection $selection, array $facets, string $baseUrl, int $perPage, string $sort, int $defaultPerPage, PaginationLinks $links): ?array
    {
        $url = static fn (FilterSelection $chosen): string => $baseUrl.$links->suffixFor(1, $perPage, $sort, $chosen->query(), $defaultPerPage);
        $groups = [];
        $chips = [];

        foreach ($catalog as $filter) {
            $chosen = $selection->map[$filter['id']] ?? [];
            $values = [];

            foreach ($filter['values'] as $value) {
                $isChosen = in_array($value['id'], $chosen, true);

                if (! $isChosen && ! in_array($value['id'], $facets[$filter['id']] ?? [], true)) {
                    continue;
                }

                $values[] = ['id' => $value['id'], 'label' => $value['label'], 'colour' => $value['colour'], 'selected' => $isChosen];
                $isChosen && $chips[] = ['label' => $value['label'], 'url' => $url($selection->toggled($filter['id'], $value['id'], $catalog))];
            }

            if (count($values) < 2 && $chosen === []) {
                continue;
            }

            $groups[] = ['id' => $filter['id'], 'name' => $filter['name'], 'display' => $filter['display'], 'count' => count($chosen), 'values' => $values];
        }

        if ($groups === []) {
            return null;
        }

        return [
            'groups' => $groups,
            'chips' => $chips,
            'clear' => $selection->isEmpty() ? null : $url(new FilterSelection()),
            'active' => count(array_filter($groups, static fn (array $group): bool => $group['count'] > 0)),
        ];
    }

    /**
     * The sorts the storefront offers (ListingSort::storefrontOptions, the default first) as links that keep the filters and `per_page` and drop `page`; the default's link has no `sort` at all. Fewer than two
     * options: no control.
     *
     * @return list<array{value: string, label: string, url: string, current: bool}>
     */
    public static function sorts(string $baseUrl, int $perPage, string $current, string $filterQuery, int $defaultPerPage, PaginationLinks $links): array
    {
        $options = ListingSort::storefrontOptions();

        if (count($options) < 2) {
            return [];
        }

        return array_map(static fn (ListingSort $sort): array => [
            // the default option's value is EMPTY: choosing it never creates a `?sort=` (the radios of the drawer send `sort=`, which the controller turns into the plain URL)
            'value' => $sort === $options[0] ? '' : $sort->value,
            'label' => (string) __('storefront.sort.'.$sort->value),
            'url' => $baseUrl.$links->suffixFor(1, $perPage, $sort->value, $filterQuery, $defaultPerPage),
            'current' => $sort->value === $current,
        ], $options);
    }
}
