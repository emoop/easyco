<?php

namespace Tests\Unit\Storefront;

use App\Storefront\Exceptions\InvalidListingQuery;
use App\Storefront\ReadModels\ListingQuery;
use App\Storefront\ReadModels\ListingScope;
use App\Storefront\ReadModels\ListingSort;
use App\Storefront\ReadModels\PerPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ListingQueryTest extends TestCase
{
    public function test_defaults(): void
    {
        $query = ListingQuery::fromRequestArray([]);

        $this->assertSame(ListingScope::ALL, $query->scope);
        $this->assertNull($query->scopeId);
        $this->assertSame(ListingSort::NEWEST, $query->sort);
        $this->assertSame(1, $query->page);
        $this->assertSame(PerPage::TWENTY_FOUR, $query->perPage);
        $this->assertFalse($query->hasPriceFilter());
    }

    public function test_a_valid_request_is_accepted_and_unknown_keys_are_ignored(): void
    {
        $query = ListingQuery::fromRequestArray([
            'category' => '12', 'sort' => 'price_desc', 'page' => '3', 'per_page' => '48',
            'price_min' => '1000', 'price_max' => 5000,
            'utm_source' => 'x', 'q' => '<script>', 'order' => 'drop table', 'scope' => 'brand',
        ]);

        $this->assertSame(
            ['scope' => 'category', 'scope_id' => '12', 'sort' => 'price_desc', 'page' => 3, 'per_page' => 48, 'price_min_minor' => 1000, 'price_max_minor' => 5000],
            $query->toArray(),
        );
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refused(): array
    {
        return [
            'unknown sort' => [['sort' => 'random'], 'sort'],
            'sort injection' => [['sort' => 'name_asc; DROP TABLE x'], 'sort'],
            'sort not a string' => [['sort' => ['newest']], 'sort'],
            'page zero' => [['page' => 0], 'page'],
            'page zero string' => [['page' => '0'], 'page'],
            'page 201' => [['page' => 201], 'page'],
            'page 201 string' => [['page' => '201'], 'page'],
            'page negative' => [['page' => '-1'], 'page'],
            'page non numeric' => [['page' => 'abc'], 'page'],
            'page decimal' => [['page' => '1.5'], 'page'],
            'page exponent' => [['page' => '1e2'], 'page'],
            'page with space' => [['page' => ' 2'], 'page'],
            'page empty string' => [['page' => ''], 'page'],
            'page array' => [['page' => [1]], 'page'],
            'page float' => [['page' => 1.0], 'page'],
            'page huge' => [['page' => '99999999999999999999'], 'page'],
            'per_page 100' => [['per_page' => 100], 'per_page'],
            'per_page 100 string' => [['per_page' => '100'], 'per_page'],
            'per_page 0' => [['per_page' => 0], 'per_page'],
            'per_page 25' => [['per_page' => 25], 'per_page'],
            'per_page text' => [['per_page' => 'all'], 'per_page'],
            'negative min' => [['price_min' => '-1'], 'price_min'],
            'negative max' => [['price_max' => -5], 'price_max'],
            'inverted range' => [['price_min' => '5000', 'price_max' => '1000'], 'price_min'],
            'decimal price' => [['price_min' => '10.50'], 'price_min'],
            'price text' => [['price_max' => 'cheap'], 'price_max'],
            'price absurd' => [['price_max' => '999999999999999'], 'price_max'],
            'price out of range' => [['price_max' => 100_000_000_000], 'price_max'],
            'category not an id' => [['category' => 'dresses'], 'category'],
            'category zero' => [['category' => '0'], 'category'],
            'category leading zero' => [['category' => '012'], 'category'],
            'category negative' => [['category' => '-3'], 'category'],
            'category overlong' => [['category' => str_repeat('9', 20)], 'category'],
            'category array' => [['category' => ['1']], 'category'],
            'brand sql' => [['brand' => '1 OR 1=1'], 'brand'],
            'tag empty' => [['tag' => ''], 'tag'],
            'two scopes' => [['category' => '1', 'brand' => '2'], 'brand'],
        ];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('refused')]
    public function test_invalid_input_is_refused_never_coerced(array $input, string $field): void
    {
        try {
            ListingQuery::fromRequestArray($input);
            $this->fail('Expected the input to be refused.');
        } catch (InvalidListingQuery $e) {
            $this->assertSame($field, $e->field);
        }
    }

    public function test_the_boundaries_are_accepted(): void
    {
        $this->assertSame(200, ListingQuery::fromRequestArray(['page' => '200'])->page);
        $this->assertSame(1, ListingQuery::fromRequestArray(['page' => 1])->page);
        $this->assertSame(12, ListingQuery::fromRequestArray(['per_page' => '12'])->perPage->value);
        $this->assertSame(0, ListingQuery::fromRequestArray(['price_min' => '0'])->priceMinMinor);

        $equal = ListingQuery::fromRequestArray(['price_min' => '700', 'price_max' => '700']);
        $this->assertSame([700, 700], [$equal->priceMinMinor, $equal->priceMaxMinor]);
    }

    public function test_an_error_message_never_echoes_the_hostile_value(): void
    {
        try {
            ListingQuery::fromRequestArray(['sort' => '<script>alert(1)</script>']);
            $this->fail('Expected a refusal.');
        } catch (InvalidListingQuery $e) {
            $this->assertStringNotContainsString('script', $e->getMessage());
        }
    }

    public function test_it_cannot_be_built_without_the_named_constructors(): void
    {
        $constructor = (new \ReflectionClass(ListingQuery::class))->getConstructor();

        $this->assertTrue($constructor->isPrivate());
    }

    public function test_create_refuses_an_id_on_the_all_scope_and_a_missing_id_on_a_real_one(): void
    {
        $this->expectException(InvalidListingQuery::class);
        ListingQuery::create(ListingScope::ALL, '5');
    }

    public function test_create_requires_an_id_for_a_scope(): void
    {
        $this->expectException(InvalidListingQuery::class);
        ListingQuery::create(ListingScope::CATEGORY, null);
    }

    public function test_with_page_revalidates(): void
    {
        $this->assertSame(2, ListingQuery::fromRequestArray([])->withPage(2)->page);

        $this->expectException(InvalidListingQuery::class);
        ListingQuery::fromRequestArray([])->withPage(201);
    }
}
