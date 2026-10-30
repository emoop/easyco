<?php

namespace App\Storefront\Filters;

use App\Storefront\Support\PaginationLinks;

/**
 * CT-3: what the filters partial prints, as plain data. For every filter of the shop (the admin's order) the values to SHOW: those that give products together with the OTHER filters' choices (the
 * facet ids, one query per filter) plus every selected value (kept visible so the visitor can remove it, even when it now gives nothing). A filter with fewer than two values to show and nothing
 * selected is not shown. Every value carries the URL that toggles it: the other filters, `per_page` and `sort` kept, `page` dropped (a new choice starts at page 1), the filters in the canonical
 * order, so one selection is one URL.
 */
final class FilterView
{
    /**
     * @param  list<array{id: int, name: string, display: string, values: list<array{id: int, label: string, colour: ?string}>}>  $catalog
     * @param  array<int, list<int>>  $facets  attribute id => the value ids that give products
     * @return array{groups: list<array<string, mixed>>, chips: list<array{label: string, url: string}>, clear: ?string}|null null = nothing to print
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

                $toggle = $url($selection->toggled($filter['id'], $value['id'], $catalog));
                $values[] = ['id' => $value['id'], 'label' => $value['label'], 'colour' => $value['colour'], 'selected' => $isChosen, 'url' => $toggle];
                $isChosen && $chips[] = ['label' => $value['label'], 'url' => $toggle];
            }

            if (count($values) < 2 && $chosen === []) {
                continue;
            }

            $groups[] = ['id' => $filter['id'], 'name' => $filter['name'], 'display' => $filter['display'], 'values' => $values];
        }

        return $groups === [] ? null : ['groups' => $groups, 'chips' => $chips, 'clear' => $selection->isEmpty() ? null : $url(new FilterSelection())];
    }
}
