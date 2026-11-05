<?php

namespace App\Storefront\Filters;

use App\Storefront\Exceptions\InvalidListingQuery;
use Illuminate\Http\Request;

/**
 * CT-3 / CT-4: what the visitor chose, as `filter key => value ids` in the CANONICAL order (the filters in the order of the shop's list: brand, season, the attributes in the admin's order, tag; the values in the
 * order the list shows them), so that one selection has one URL. A key is an attribute's id or one of the reserved words `brand`, `season`, `tag` (a built-in that is switched off or has no values is not in the list: 404).
 *
 * The URL form is `filter[<attribute id or reserved word>]=<value id>,<value id>` (comma = OR inside one filter; separate filters = AND). Everything that is not exactly that is the storefront's 404 (InvalidListingQuery):
 * a `filter` that is not an array, a key or a value that is not a plain whole number (no sign, no leading zero, at most 18 digits), an attribute that is not a filter of THIS shop, a value that does not
 * belong to it, a value given twice, an empty list, more than MAX_FILTERS filters, MAX_PER_FILTER values in one or MAX_TOTAL in all. The same selection in another order is valid, but `canonical` is false:
 * the controller answers 301 to the canonical URL (before any query).
 */
final readonly class FilterSelection
{
    public const MAX_FILTERS = 6;

    public const MAX_PER_FILTER = 10;

    public const MAX_TOTAL = 15;

    /**
     * P2: `featured` is the «Препоръчани» switch (the URL's `featured=1`, a filter of its own kind: no values, nothing to resolve against a list).
     *
     * @param array<int|string, list<int>> $map filter key (attribute id or `brand` | `season` | `tag`) => value ids, canonical order
     */
    public function __construct(public array $map = [], public bool $canonical = true, public bool $featured = false)
    {
    }

    public function isEmpty(): bool
    {
        return $this->map === [] && ! $this->featured;
    }

    /** P2: what the product query is constrained by: the filters' map and, when the switch is on, `featured` (FilterConstraint::SWITCHES). @return array<int|string, list<int>> */
    public function constraintMap(): array
    {
        return $this->featured ? $this->map + ['featured' => [1]] : $this->map;
    }

    /** P2: the same selection with the switch turned on or off. */
    public function withFeatured(bool $on): self
    {
        return new self($this->map, $this->canonical, $on);
    }

    /**
     * @param  list<array{id: int|string, name: string, display: string, values: list<array{id: int, label: string, colour: ?string}>}>  $catalog
     * @throws InvalidListingQuery
     */
    public static function fromRequest(Request $request, array $catalog): self
    {
        $featured = self::featured($request, $catalog);

        if (! $request->query->has('filter')) {
            return new self([], true, $featured);
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
        $arrayForm = false;

        foreach ($raw as $key => $list) {
            $attribute = in_array($key, FilterConstraint::BUILTIN, true) ? $key : self::number((string) $key);

            if ($attribute === null || ! isset($known[$attribute]) || isset($given[$attribute])) {
                throw new InvalidListingQuery('filter', 'names an unknown filter');
            }

            // CD-1b: the form sends `filter[12][]=7&filter[12][]=9` (a plain list of whole numbers, one level deep); it is valid and ALWAYS a 301 to the canonical comma form
            if (is_array($list)) {
                if ($list === [] || ! array_is_list($list)) {
                    throw new InvalidListingQuery('filter', 'is not a list of values');
                }

                $arrayForm = true;
                $tokens = $list;
            } elseif (is_string($list) && $list !== '') {
                $tokens = explode(',', $list);
            } else {
                throw new InvalidListingQuery('filter', 'names an unknown filter');
            }

            $values = [];

            foreach ($tokens as $token) {
                $value = is_string($token) ? self::number($token) : null;

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

        return new self($map, $map === $given && ! $arrayForm, $featured);
    }

    /**
     * P2: the `featured` switch. Not a filter of this shop's list (hidden, or never switched on) = the storefront's 404 for ANY value, as for any hidden filter; when it IS one, `featured=1` turns it on and any other
     * value (`2`, empty, an array) is no filter at all.
     */
    private static function featured(Request $request, array $catalog): bool
    {
        if (! $request->query->has('featured')) {
            return false;
        }

        if (! in_array('featured', array_column($catalog, 'id'), true)) {
            throw new InvalidListingQuery('featured', 'is not a filter of this shop');
        }

        return ($request->query->all()['featured'] ?? null) === '1';
    }

    /** The selection with this value turned on (or off when it is on), the order kept canonical. */
    public function toggled(int|string $attribute, int $value, array $catalog): self
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

        return new self($ordered, true, $this->featured);
    }

    /** `filter[2]=5,9&filter[7]=1` (brackets and commas as they are: readable, and PHP reads them back the same) or '' for nothing. */
    public function query(): string
    {
        $parts = [];

        foreach ($this->map as $attribute => $values) {
            $parts[] = 'filter['.$attribute.']='.implode(',', $values);
        }

        $this->featured && $parts[] = 'featured=1';

        return implode('&', $parts);
    }

    private static function number(string $text): ?int
    {
        return preg_match('/\A[1-9][0-9]{0,17}\z/', $text) === 1 ? (int) $text : null;
    }
}
