<?php

namespace App\Storefront\ReadModels;

use App\Storefront\Exceptions\InvalidListingQuery;

/**
 * What a listing page asks for. Immutable, and it can ONLY be built through create() / fromRequestArray(), which
 * validate: an invalid value throws InvalidListingQuery, it is never silently coerced (storefront-design.md §2.3, §6).
 *
 * Ids in this project are auto-increment integers (catalog_* tables), carried as strings: `[1-9][0-9]{0,18}`.
 * Price bounds are non-negative integers in MINOR units. Both are validated and carried here, but the reader refuses
 * to apply them in S1 (ListingOptionNotAvailable): a price filter or price sort needs S7's `price_from_minor`.
 */
final readonly class ListingQuery
{
    public const MAX_PAGE = 200;

    public const MAX_PRICE_MINOR = 99_999_999_999;

    /** Request keys that select a scope; fromRequestArray() accepts at most one of them. */
    private const SCOPE_KEYS = ['category' => ListingScope::CATEGORY, 'brand' => ListingScope::BRAND, 'tag' => ListingScope::TAG];

    private function __construct(
        public ListingScope $scope,
        public ?string $scopeId,
        public ListingSort $sort,
        public int $page,
        public PerPage $perPage,
        public ?int $priceMinMinor,
        public ?int $priceMaxMinor,
    ) {
    }

    /**
     * @param int|string|null $scopeId required for category/brand/tag, forbidden for ALL
     */
    public static function create(
        ListingScope $scope = ListingScope::ALL,
        int|string|null $scopeId = null,
        mixed $sort = ListingSort::NEWEST,
        mixed $page = 1,
        mixed $perPage = PerPage::TWENTY_FOUR,
        mixed $priceMin = null,
        mixed $priceMax = null,
    ): self {
        if ($scope === ListingScope::ALL) {
            if ($scopeId !== null) {
                throw new InvalidListingQuery('scope', 'takes no id');
            }
        } else {
            $scopeId = self::id($scopeId, $scope->value);
        }

        $min = self::minor($priceMin, 'price_min');
        $max = self::minor($priceMax, 'price_max');

        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidListingQuery('price_min', 'is above price_max');
        }

        return new self(
            $scope,
            $scopeId === null ? null : (string) $scopeId,
            self::sort($sort),
            self::page($page),
            self::perPage($perPage),
            $min,
            $max,
        );
    }

    /**
     * Whitelists `category|brand|tag` (at most one), `sort`, `page`, `per_page`, `price_min`, `price_max`; unknown
     * keys are ignored. A null value means "not given"; an empty string is NOT "not given" (it is refused).
     *
     * @param array<array-key, mixed> $input
     */
    public static function fromRequestArray(array $input): self
    {
        $scope = ListingScope::ALL;
        $scopeId = null;

        foreach (self::SCOPE_KEYS as $key => $candidate) {
            if (array_key_exists($key, $input) && $input[$key] !== null) {
                if ($scope !== ListingScope::ALL) {
                    throw new InvalidListingQuery($key, 'cannot be combined with another scope');
                }

                if (! is_string($input[$key]) && ! is_int($input[$key])) {
                    throw new InvalidListingQuery($key, 'is not a valid id');
                }

                $scope = $candidate;
                $scopeId = $input[$key];
            }
        }

        return self::create(
            $scope,
            $scopeId,
            $input['sort'] ?? ListingSort::NEWEST,
            $input['page'] ?? 1,
            $input['per_page'] ?? PerPage::TWENTY_FOUR,
            $input['price_min'] ?? null,
            $input['price_max'] ?? null,
        );
    }

    public function withPage(int $page): self
    {
        return new self($this->scope, $this->scopeId, $this->sort, self::page($page), $this->perPage, $this->priceMinMinor, $this->priceMaxMinor);
    }

    public function hasPriceFilter(): bool
    {
        return $this->priceMinMinor !== null || $this->priceMaxMinor !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope->value,
            'scope_id' => $this->scopeId,
            'sort' => $this->sort->value,
            'page' => $this->page,
            'per_page' => $this->perPage->value,
            'price_min_minor' => $this->priceMinMinor,
            'price_max_minor' => $this->priceMaxMinor,
        ];
    }

    private static function id(mixed $value, string $field): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1) {
            throw new InvalidListingQuery($field, 'is not a valid id');
        }

        return $value;
    }

    private static function sort(mixed $value): ListingSort
    {
        if ($value instanceof ListingSort) {
            return $value;
        }

        if (is_string($value) && ($sort = ListingSort::tryFrom($value)) !== null) {
            return $sort;
        }

        throw new InvalidListingQuery('sort', 'is not a known sort');
    }

    private static function page(mixed $value): int
    {
        $page = self::integer($value, 'page', 6);

        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new InvalidListingQuery('page', 'is out of range');
        }

        return $page;
    }

    private static function perPage(mixed $value): PerPage
    {
        if ($value instanceof PerPage) {
            return $value;
        }

        return PerPage::tryFrom(self::integer($value, 'per_page', 3)) ?? throw new InvalidListingQuery('per_page', 'is not an allowed size');
    }

    private static function minor(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }

        $minor = self::integer($value, $field, 12);

        if ($minor < 0 || $minor > self::MAX_PRICE_MINOR) {
            throw new InvalidListingQuery($field, 'is out of range');
        }

        return $minor;
    }

    /** An int, or a string of plain digits (a sign, a dot, an exponent or a space is refused): nothing else. */
    private static function integer(mixed $value, string $field, int $maxDigits): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[0-9]{1,'.$maxDigits.'}$/D', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidListingQuery($field, 'is not a whole number');
    }
}
