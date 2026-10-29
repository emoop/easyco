<?php

namespace Tests\Feature\HtmlBlocks;

use App\Storefront\Conditions\CurrentProduct;
use App\Storefront\Conditions\ProductConditions;
use App\Storefront\Conditions\ProductContext;
use App\Storefront\Reader\CatalogReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * HB-2: the product conditions of an HTML block and the named predicates a theme calls from Blade: ONE logic. The validator (closed keys, ids that exist, caps, duplicates, a `false` that is a
 * condition), every predicate against real catalog fixtures (on sale, a category and its DESCENDANTS, a tag, a season, an attribute value), the AND / OR rule, and `@productIs`.
 */
class ProductConditionsTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const CLOTHING = 1;

    private const DRESSES = 2;

    private const SHOES = 3;

    private const TAG_HOT = 1;

    private const TAG_ECO = 2;

    private const SUMMER = 1;

    private const WINTER = 2;

    private const SIZE = 5;

    private const SIZE_S = 5001;

    private const SIZE_M = 5002;

    private const COLOUR = 6;

    private const COLOUR_RED = 6001;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        ProductContext::forgetShared();

        $this->makeCategory(self::CLOTHING, 'Clothing', 'clothing');
        $this->makeCategory(self::DRESSES, 'Dresses', 'dresses', self::CLOTHING);
        $this->makeCategory(self::SHOES, 'Shoes', 'shoes');
        $this->makeTag(self::TAG_HOT, 'Hot', 'hot');
        $this->makeTag(self::TAG_ECO, 'Eco', 'eco');
        $this->makeAttribute(self::SIZE, 'Size', ['S', 'M']);
        $this->makeAttribute(self::COLOUR, 'Colour', ['Red']);
        DB::table('catalog_seasons')->insert([['id' => self::SUMMER, 'name' => 'Summer', 'slug' => 'summer'], ['id' => self::WINTER, 'name' => 'Winter', 'slug' => 'winter']]);

        // 1: a dress on sale, hot, summer; 2: a dress NOT on sale, eco, winter; 3: shoes on sale, no tag, no season; 4: a shirt (variable) in Clothing with sizes S and M
        $this->makeProduct(1, ['name' => 'Sale dress', 'slug' => 'sale-dress', 'price' => '100.00', 'sale' => '70.00', 'stock' => 3, 'categories' => [self::DRESSES], 'tags' => [self::TAG_HOT]]);
        $this->makeProduct(2, ['name' => 'Plain dress', 'slug' => 'plain-dress', 'price' => '100.00', 'stock' => 3, 'categories' => [self::DRESSES], 'tags' => [self::TAG_ECO]]);
        $this->makeProduct(3, ['name' => 'Shoes', 'slug' => 'shoes', 'price' => '80.00', 'sale' => '60.00', 'stock' => 3, 'categories' => [self::SHOES]]);
        $this->makeProduct(4, [
            'type' => 'variable', 'name' => 'Shirt', 'slug' => 'shirt', 'categories' => [self::CLOTHING],
            'variations' => [['attrs' => [self::SIZE => 'S'], 'price' => '40.00', 'stock' => 2], ['attrs' => [self::SIZE => 'M'], 'price' => '40.00', 'stock' => 2]],
        ]);
        DB::table('catalog_products')->where('id', 1)->update(['season_id' => self::SUMMER]);
        DB::table('catalog_products')->where('id', 2)->update(['season_id' => self::WINTER]);
        $this->app->forgetScopedInstances();
    }

    private function context(string $slug): ProductContext
    {
        $this->app->forgetScopedInstances();
        ProductContext::forgetShared();

        return ProductContext::fromPage(app(CatalogReader::class)->product($slug) ?? $this->fail('no product '.$slug));
    }

    /** @param  array<string, mixed>|null  $conditions */
    private function holds(string $slug, ?array $conditions): bool
    {
        return ProductConditions::matches($this->context($slug), $conditions);
    }

    // ---- the validator ----------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: mixed, 1: ?string}> [the conditions, the key of the error (null = valid)] */
    public static function conditions(): array
    {
        $rule = static fn (mixed $attribute, mixed $values): array => ['attribute' => $attribute, 'values' => $values];

        return [
            'nothing' => [[], null],
            'null' => [null, null],
            'on sale' => [['on_sale' => true], null],
            'NOT on sale' => [['on_sale' => false], null],
            'categories' => [['categories' => [1, 2, 3]], null],
            'ids as digit strings' => [['categories' => ['1', '2']], null],
            'tags' => [['tags' => [1, 2]], null],
            'seasons' => [['seasons' => [1, 2]], null],
            'an attribute rule' => [['attributes' => [$rule(5, [5001, 5002])]], null],
            'two attribute rules' => [['attributes' => [$rule(5, [5001]), $rule(6, [6001])]], null],

            'an unknown key' => [['font' => 'x'], 'conditions'],
            'is_new is not a condition: the shop has no such concept' => [['is_new' => true], 'conditions'],
            'not an array' => [['on_sale' => true] === 1 ? [] : 'red', 'conditions'],
            'on_sale as the text yes' => [['on_sale' => 'yes'], 'on_sale'],
            'on_sale as the text false' => [['on_sale' => 'false'], 'on_sale'],
            'on_sale as 1' => [['on_sale' => 1], 'on_sale'],
            'a category that does not exist' => [['categories' => [999]], 'categories'],
            'a tag that does not exist' => [['tags' => [999]], 'tags'],
            'a season that does not exist' => [['seasons' => [999]], 'seasons'],
            'a negative id' => [['categories' => [-1]], 'categories'],
            'id 0' => [['categories' => [0]], 'categories'],
            'a word' => [['tags' => ['abc']], 'tags'],
            'a decimal' => [['categories' => [1.5]], 'categories'],
            'CSS-like text in an id' => [['categories' => ['1;2']], 'categories'],
            'an id of 600 digits' => [['categories' => [str_repeat('9', 600)]], 'categories'],
            'a list inside the list' => [['categories' => [[1]]], 'categories'],
            'a map instead of a list' => [['categories' => ['a' => 1]], 'categories'],
            'a text instead of a list' => [['tags' => '1'], 'tags'],
            '51 categories' => [['categories' => range(1, 51)], 'categories'],
            '21 seasons' => [['seasons' => range(1, 21)], 'seasons'],
            '51 tags' => [['tags' => range(1, 51)], 'tags'],
            'six attribute rules' => [['attributes' => array_fill(0, 6, $rule(5, [5001]))], 'attributes'],
            'the same attribute twice' => [['attributes' => [$rule(5, [5001]), $rule(5, [5002])]], 'attributes'],
            'a value of another attribute' => [['attributes' => [$rule(5, [6001])]], 'attributes'],
            'a rule with no values' => [['attributes' => [$rule(5, [])]], 'attributes'],
            'an attribute that does not exist' => [['attributes' => [$rule(99, [1])]], 'attributes'],
            'an attribute that is not an id' => [['attributes' => [$rule('size', [5001])]], 'attributes'],
            'a rule with a key of its own' => [['attributes' => [['attribute' => 5, 'values' => [5001], 'x' => 1]]], 'attributes'],
            '21 values in a rule' => [['attributes' => [$rule(5, range(5001, 5021))]], 'attributes'],
            'attributes that are not a list' => [['attributes' => ['a' => $rule(5, [5001])]], 'attributes'],
        ];
    }

    #[DataProvider('conditions')]
    public function test_the_validator_accepts_only_conditions_it_understands_and_whose_ids_exist(mixed $conditions, ?string $refused): void
    {
        [, $errors] = ProductConditions::validate($conditions);

        $refused === null ? $this->assertSame([], $errors, json_encode($conditions)) : $this->assertArrayHasKey($refused, $errors, json_encode([$conditions, $errors]));
    }

    public function test_the_caps_are_exact_fifty_categories_twenty_seasons_five_rules_twenty_values(): void
    {
        foreach (range(100, 149) as $id) {
            $this->makeCategory($id, 'C'.$id, 'c'.$id);
        }

        foreach (range(10, 29) as $id) {
            DB::table('catalog_seasons')->insert(['id' => $id, 'name' => 'S'.$id, 'slug' => 's'.$id]);
        }

        foreach (range(2, 21) as $n) {
            DB::table('catalog_attribute_values')->insert(['id' => 5000 + $n + 100, 'attribute_definition_id' => 5, 'value' => 'v'.$n, 'sort_order' => $n, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->assertSame([], ProductConditions::validate(['categories' => range(100, 149)])[1], '50 categories');
        $this->assertArrayHasKey('categories', ProductConditions::validate(['categories' => range(100, 150)])[1], '51 categories');
        $this->assertSame([], ProductConditions::validate(['seasons' => range(10, 29)])[1], '20 seasons');
        $this->assertArrayHasKey('seasons', ProductConditions::validate(['seasons' => range(10, 30)])[1], '21 seasons');

        $values = array_merge([5001, 5002], range(5000 + 2 + 100, 5000 + 19 + 100));
        $this->assertCount(20, $values);
        $this->assertSame([], ProductConditions::validate(['attributes' => [['attribute' => 5, 'values' => $values]]])[1], '20 values');
        $this->assertArrayHasKey('attributes', ProductConditions::validate(['attributes' => [['attribute' => 5, 'values' => [...$values, 5000 + 20 + 100]]]])[1], '21 values');

        $five = array_map(fn (int $n): array => ['attribute' => 7 + $n, 'values' => [1]], range(0, 4));
        foreach (range(7, 11) as $id) {
            $this->makeAttribute($id, 'A'.$id, ['x']);
        }
        $five = array_map(fn (int $id): array => ['attribute' => $id, 'values' => [$id * 1000 + 1]], range(7, 11));
        $this->assertSame([], ProductConditions::validate(['attributes' => $five])[1], '5 rules');
    }

    public function test_duplicates_go_and_the_result_is_normalised(): void
    {
        [$clean, $errors] = ProductConditions::validate(['categories' => ['2', 2, 1], 'tags' => [2, 1, 2], 'attributes' => [['attribute' => '5', 'values' => [5002, 5001, 5001]]]]);

        $this->assertSame([], $errors);
        $this->assertSame(['categories' => [1, 2], 'tags' => [1, 2], 'attributes' => [['attribute' => 5, 'values' => [5001, 5002]]]], $clean);
    }

    public function test_nothing_set_is_stored_as_null_and_a_false_is_a_condition_that_is_kept(): void
    {
        $this->assertNull(ProductConditions::storable(null));
        $this->assertNull(ProductConditions::storable([]));
        $this->assertNull(ProductConditions::storable(['categories' => [], 'tags' => [], 'attributes' => [], 'on_sale' => null]));

        $this->assertSame(['on_sale' => false], ProductConditions::storable(['on_sale' => false]), '`false` is not the same as an absent key');
        $this->assertSame(['on_sale' => true], ProductConditions::storable(['on_sale' => true]));
        $this->assertTrue(ProductConditions::isSet(['on_sale' => false]));
        $this->assertFalse(ProductConditions::isSet([]));
    }

    public function test_conditions_that_do_not_validate_are_never_stored(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProductConditions::storable(['categories' => [999]]);
    }

    public function test_the_messages_are_in_both_languages(): void
    {
        app()->setLocale('en');
        $this->assertSame('At most 50 chosen.', ProductConditions::validate(['categories' => range(1, 51)])[1]['categories']);
        app()->setLocale('bg');
        $this->assertSame('Най-много 50 избрани.', ProductConditions::validate(['categories' => range(1, 51)])[1]['categories']);
    }

    public function test_the_form_state_round_trips_and_drops_what_no_longer_exists(): void
    {
        $stored = ['on_sale' => false, 'categories' => [self::DRESSES, 777], 'tags' => [self::TAG_HOT], 'seasons' => [self::SUMMER], 'attributes' => [['attribute' => self::SIZE, 'values' => [self::SIZE_S, 9999]]]];

        $state = ProductConditions::formState($stored);

        $this->assertSame('no', $state['on_sale']);
        $this->assertSame([self::DRESSES], $state['categories'], 'the vanished category is dropped');
        $this->assertSame([['attribute' => self::SIZE, 'values' => [self::SIZE_S]]], $state['attributes']);
        $this->assertSame(['on_sale' => false, 'categories' => [self::DRESSES], 'tags' => [self::TAG_HOT], 'seasons' => [self::SUMMER], 'attributes' => [['attribute' => self::SIZE, 'values' => [self::SIZE_S]]]], ProductConditions::storable(ProductConditions::fromForm($state)));
        $this->assertSame('all', ProductConditions::formState(null)['on_sale']);
        $this->assertSame([], ProductConditions::fromForm(ProductConditions::formState(null)), 'an untouched form has no conditions');
    }

    // ---- the predicates ---------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: bool}> */
    public static function cases(): array
    {
        $rule = static fn (int $attribute, array $values): array => ['attribute' => $attribute, 'values' => $values];

        return [
            'on sale: a product on sale' => ['sale-dress', ['on_sale' => true], true],
            'on sale: a product not on sale' => ['plain-dress', ['on_sale' => true], false],
            'on sale: the shoes are on sale' => ['shoes', ['on_sale' => true], true],
            'NOT on sale: the sale product does not match' => ['sale-dress', ['on_sale' => false], false],
            'NOT on sale: the plain product matches' => ['plain-dress', ['on_sale' => false], true],
            'NOT on sale: the variable shirt matches' => ['shirt', ['on_sale' => false], true],
            'NOT on sale: the shoes do not match' => ['shoes', ['on_sale' => false], false],

            'category: the product is in it' => ['sale-dress', ['categories' => [self::DRESSES]], true],
            'category: a DESCENDANT of the chosen one (Dresses is in Clothing)' => ['sale-dress', ['categories' => [self::CLOTHING]], true],
            'category: another branch' => ['sale-dress', ['categories' => [self::SHOES]], false],
            'category: the parent product is not in the child category' => ['shirt', ['categories' => [self::DRESSES]], false],
            'category: the shirt is in Clothing itself' => ['shirt', ['categories' => [self::CLOTHING]], true],
            'category: shoes are not in Clothing' => ['shoes', ['categories' => [self::CLOTHING]], false],
            'category: any of two' => ['shoes', ['categories' => [self::DRESSES, self::SHOES]], true],

            'tag: has it' => ['sale-dress', ['tags' => [self::TAG_HOT]], true],
            'tag: has the other' => ['sale-dress', ['tags' => [self::TAG_ECO]], false],
            'tag: any of two' => ['plain-dress', ['tags' => [self::TAG_HOT, self::TAG_ECO]], true],
            'tag: no tags at all' => ['shoes', ['tags' => [self::TAG_HOT, self::TAG_ECO]], false],

            'season: the product\'s season' => ['sale-dress', ['seasons' => [self::SUMMER]], true],
            'season: another season' => ['sale-dress', ['seasons' => [self::WINTER]], false],
            'season: any of two' => ['plain-dress', ['seasons' => [self::SUMMER, self::WINTER]], true],
            'season: a product with no season' => ['shoes', ['seasons' => [self::SUMMER, self::WINTER]], false],

            'attribute: a value the shirt has' => ['shirt', ['attributes' => [$rule(self::SIZE, [self::SIZE_S])]], true],
            'attribute: the other value' => ['shirt', ['attributes' => [$rule(self::SIZE, [self::SIZE_M])]], true],
            'attribute: any of two values' => ['shirt', ['attributes' => [$rule(self::SIZE, [self::SIZE_S, self::SIZE_M])]], true],
            'attribute: an attribute the shirt does not have' => ['shirt', ['attributes' => [$rule(self::COLOUR, [self::COLOUR_RED])]], false],
            'attribute: a simple product has none' => ['sale-dress', ['attributes' => [$rule(self::SIZE, [self::SIZE_S])]], false],

            'AND: both hold' => ['sale-dress', ['on_sale' => true, 'categories' => [self::DRESSES]], true],
            'AND: on sale but in another category' => ['shoes', ['on_sale' => true, 'categories' => [self::DRESSES]], false],
            'AND: the right category but not on sale' => ['plain-dress', ['on_sale' => true, 'categories' => [self::DRESSES]], false],
            'AND: three conditions, all hold' => ['sale-dress', ['on_sale' => true, 'tags' => [self::TAG_HOT], 'seasons' => [self::SUMMER]], true],
            'AND: three conditions, one fails' => ['sale-dress', ['on_sale' => true, 'tags' => [self::TAG_HOT], 'seasons' => [self::WINTER]], false],
            'AND: a category and an attribute' => ['shirt', ['categories' => [self::CLOTHING], 'attributes' => [$rule(self::SIZE, [self::SIZE_M])]], true],
            'AND: a category and an attribute the product lacks' => ['shirt', ['categories' => [self::CLOTHING], 'attributes' => [$rule(self::COLOUR, [self::COLOUR_RED])]], false],
            'OR inside a list: either category' => ['plain-dress', ['categories' => [self::SHOES, self::DRESSES]], true],

            'a category that was deleted matches nothing, without an error' => ['sale-dress', ['categories' => [777]], false],
            'a tag that was deleted matches nothing' => ['sale-dress', ['tags' => [777]], false],
            'conditions that are not valid never match' => ['sale-dress', ['categories' => 'x'], false],
            'no conditions match everything' => ['sale-dress', [], true],
        ];
    }

    /** @param  array<string, mixed>  $conditions */
    #[DataProvider('cases')]
    public function test_each_predicate_against_the_catalog(string $slug, array $conditions, bool $expected): void
    {
        $this->assertSame($expected, $this->holds($slug, $conditions));
    }

    public function test_null_conditions_match_every_product_and_false_never_matches(): void
    {
        foreach (['sale-dress', 'plain-dress', 'shoes', 'shirt'] as $slug) {
            $this->assertTrue(ProductConditions::matches($this->context($slug), null), $slug);
            $this->assertFalse(ProductConditions::matches($this->context($slug), false), $slug);
        }
    }

    public function test_the_sale_state_is_the_one_the_price_prints(): void
    {
        $this->assertTrue($this->holds('sale-dress', ['on_sale' => true]));

        app(\App\Services\ProductPricingAndStock::class)->writeSalePrice('101', null);

        $this->assertFalse($this->holds('sale-dress', ['on_sale' => true]), 'the sale is switched off: the price block has no regular price any more, and neither does the condition');
        $this->assertTrue($this->holds('sale-dress', ['on_sale' => false]));
    }

    public function test_the_context_asks_the_database_only_for_what_a_predicate_needs(): void
    {
        $context = $this->context('sale-dress');

        DB::flushQueryLog();
        DB::enableQueryLog();
        ProductConditions::matches($context, ['on_sale' => true]);
        $this->assertSame(0, count(DB::getQueryLog()), 'the sale state is the price block the page already has: no query');

        ProductConditions::matches($context, ['tags' => [self::TAG_HOT]]);
        ProductConditions::matches($context, ['tags' => [self::TAG_ECO]]);
        $this->assertSame(1, count(DB::getQueryLog()), 'the tags are read once and remembered');
        DB::disableQueryLog();
    }

    // ---- the theme hook ----------------------------------------------------------------------------------------------------------------------

    private function render(string $template, ?string $slug): string
    {
        // the context first: it resets the scoped instances, and the holder of the current product is one of them
        $context = $slug === null ? null : $this->context($slug);
        app(CurrentProduct::class)->set($context);

        return trim(Blade::render($template));
    }

    public function test_product_is_a_blade_directive_over_the_same_predicates(): void
    {
        $on = "@productIs('on_sale')YES @elseproductIs('in_category', 3)SHOES @else NO @endproductIs";

        $this->assertSame('YES', $this->render($on, 'sale-dress'));
        $this->assertSame('NO', $this->render($on, 'plain-dress'));
        $this->assertSame('YES', $this->render($on, 'shoes'));

        $this->assertSame('Y', $this->render("@productIs('on_sale', false)Y @else N @endproductIs", 'plain-dress'));
        $this->assertSame('N', $this->render("@productIs('on_sale', false)Y @else N @endproductIs", 'sale-dress'));
        $this->assertSame('Y', $this->render("@productIs('in_category', 1)Y @else N @endproductIs", 'sale-dress'), 'a descendant counts');
        $this->assertSame('Y', $this->render("@productIs('in_category', [3, 2])Y @else N @endproductIs", 'sale-dress'));
        $this->assertSame('Y', $this->render("@productIs('has_tag', 1)Y @else N @endproductIs", 'sale-dress'));
        $this->assertSame('Y', $this->render("@productIs('in_season', 1)Y @else N @endproductIs", 'sale-dress'));
        $this->assertSame('Y', $this->render("@productIs('has_attribute', 5, [5001])Y @else N @endproductIs", 'shirt'));
        $this->assertSame('Y', $this->render("@productIs('has_attribute', 5)Y @else N @endproductIs", 'shirt'), 'no values = any value of the attribute');
        $this->assertSame('N', $this->render("@productIs('has_attribute', 6)Y @else N @endproductIs", 'shirt'));
    }

    public function test_outside_a_product_page_the_directive_is_false_without_an_error(): void
    {
        foreach (["@productIs('on_sale')Y @else N @endproductIs", "@productIs('in_category', 1)Y @else N @endproductIs", "@productIs('on_sale', false)Y @else N @endproductIs"] as $template) {
            $this->assertSame('N', $this->render($template, null), $template);
        }
    }

    public function test_an_unknown_predicate_throws_outside_production_and_is_false_in_production(): void
    {
        try {
            $this->render("@productIs('on_sail')Y @else N @endproductIs", 'sale-dress');
            $this->fail('a misspelt predicate must be a developer error');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('on_sail', $e->getMessage());
            $this->assertStringContainsString('on_sale', $e->getMessage(), 'the message lists the known predicates');
        }

        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->assertTrue($this->app->isProduction());
        $this->assertSame('N', $this->render("@productIs('on_sail')Y @else N @endproductIs", 'sale-dress'));
        $this->assertFalse(ProductConditions::is('nonsense'));
    }

    public function test_the_directive_and_the_conditions_use_one_registry(): void
    {
        $registry = ProductConditions::predicates();

        $this->assertSame(['on_sale', 'in_category', 'has_tag', 'in_season', 'has_attribute'], array_keys($registry), 'there is no is_new: the shop has no such concept');

        foreach (['sale-dress' => true, 'plain-dress' => false] as $slug => $onSale) {
            $context = $this->context($slug);
            $this->assertSame($onSale, $registry['on_sale']($context, []));
            $this->assertSame(ProductConditions::matches($context, ['on_sale' => true]), $this->render("@productIs('on_sale')1 @else 0 @endproductIs", $slug) === '1');
        }
    }
}
