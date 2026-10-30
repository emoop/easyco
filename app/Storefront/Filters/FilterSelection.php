<?php

namespace App\Storefront\Filters;

use App\Storefront\Exceptions\InvalidListingQuery;
use Illuminate\Http\Request;

/**
 * CT-3: what the visitor chose, as `attribute id => value ids` in the CANONICAL order (the filters in the admin's order, the values in the admin's order), so that one selection has one URL.
 *
 * The URL form is `filter[<attribute id>]=<value id>,<value id>` (comma = OR inside one filter; separate filters = AND). Everything that is not exactly that is the storefront's 404 (InvalidListingQuery):
 * a `filter` that is not an array, a key or a value that is not a plain whole number (no sign, no leading zero, at most 18 digits), an attribute that is not a filter of THIS shop, a value that does not
 * belong to it, a value given twice, an empty list, more than MAX_FILTERS filters, MAX_PER_FILTER values in one or MAX_TOTAL in all. The same selection in another order is valid, but `canonical` is false:
 * the controller answers 301 to the canonical URL (before any query).
 */
final readonly class FilterSelection
{
    public const MAX_FILTERS = 6;

    public const MAX_PER_FILTER = 10;

    public const MAX_TOTAL = 15;

    /** @param array<int, list<int>> $map attribute id => value ids, canonical order */
    public function __construct(public array $map = [], public bool $canonical = true)
    {
    }

    public function isEmpty(): bool
    {
        return $this->map === [];
    }

    /**
     * @param  list<array{id: int, name: string, display: string, values: list<array{id: int, label: string, colour: ?string}>}>  $catalog
     * @throws InvalidListingQuery
     */
    public static function fromRequest(Request $request, array $catalog): self
    {
        if (! $request->query->has('filter')) {
            return new self();
        }

        $raw = $request->query->all()['filter'] ?? null;

        if (! is_array($raw) || $raw === []) {
            throw new InvalidListingQuery('filter', 'is not a list of filters');
        }

        if (count($raw) > self::MAX_FILTERS) {
            throw new InvalidListingQuery('filter', 'has too many filters');
        }

        $known = [];

        foreach ($catalog as $filter) {
            $known[$filter['id']] = array_column($filter['values'], 'id');
        }

        $given = [];
        $total = 0;

        foreach ($raw as $key => $list) {
            $attribute = self::number((string) $key);

            if ($attribute === null || ! isset($known[$attribute]) || isset($given[$attribute]) || ! is_string($list) || $list === '') {
                throw new InvalidListingQuery('filter', 'names an unknown filter');
            }

            $values = [];

            foreach (explode(',', $list) as $token) {
                $value = self::number($token);

                if ($value === null || ! in_array($value, $known[$attribute], true) || in_array($value, $values, true)) {
                    throw new InvalidListingQuery('filter', 'names an unknown value');
                }

                $values[] = $value;
            }

            if (count($values) > self::MAX_PER_FILTER || ($total += count($values)) > self::MAX_TOTAL) {
                throw new InvalidListingQuery('filter', 'has too many values');
            }

            $given[$attribute] = $values;
        }

        // the canonical order: filters by the admin's order, values by the admin's order
        $map = [];

        foreach ($known as $attribute => $order) {
            if (isset($given[$attribute])) {
                $map[$attribute] = array_values(array_filter($order, static fn (int $id): bool => in_array($id, $given[$attribute], true)));
            }
        }

        return new self($map, $map === $given);
    }

    /** The selection with this value turned on (or off when it is on), the order kept canonical. */
    public function toggled(int $attribute, int $value, array $catalog): self
    {
        $map = $this->map;
        $values = $map[$attribute] ?? [];
        $values = in_array($value, $values, true) ? array_values(array_diff($values, [$value])) : [...$values, $value];
        $values === [] ? ($map = array_diff_key($map, [$attribute => 1])) : ($map[$attribute] = $values);

        $ordered = [];

        foreach ($catalog as $filter) {
            if (isset($map[$filter['id']])) {
                $order = array_column($filter['values'], 'id');
                $ordered[$filter['id']] = array_values(array_filter($order, static fn (int $id): bool => in_array($id, $map[$filter['id']], true)));
            }
        }

        return new self($ordered);
    }

    /** `filter[2]=5,9&filter[7]=1` (brackets and commas as they are: readable, and PHP reads them back the same) or '' for nothing. */
    public function query(): string
    {
        $parts = [];

        foreach ($this->map as $attribute => $values) {
            $parts[] = 'filter['.$attribute.']='.implode(',', $values);
        }

        return implode('&', $parts);
    }

    private static function number(string $text): ?int
    {
        return preg_match('/\A[1-9][0-9]{0,17}\z/', $text) === 1 ? (int) $text : null;
    }
}
