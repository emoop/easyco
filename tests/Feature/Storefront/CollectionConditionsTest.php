<?php

namespace Tests\Feature\Storefront;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Conditions\ProductConditions;
use App\Storefront\Conditions\ProductContext;
use App\Storefront\Reader\CatalogReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * P6e-3 Part A: the HTML-block condition `in_collection` (key `collections`). It matches only the products of the listed collections; while the collections are OFF it is FALSE (the block is hidden — the safer
 * reading: a block that was meant for a collection never shows to everybody); the read is lazy (a page without a conditioned block pays nothing) and the switch rides inside the statement (no settings query).
 */
class CollectionConditionsTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');

        foreach ([1 => 'Wedding', 2 => 'Summer', 3 => 'Empty'] as $id => $name) {
            DB::table('catalog_collections')->insert(['id' => $id, 'name' => $name, 'slug' => strtolower($name), 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
        }

        foreach ([1 => [1], 2 => [1, 2], 3 => [2], 4 => []] as $id => $collections) {
            $this->makeProduct($id, ['name' => "P{$id}", 'slug' => "p{$id}", 'price' => '10.00', 'stock' => 3, 'categories' => [1]]);

            foreach ($collections as $collection) {
                DB::table('catalog_product_collections')->insert(['product_id' => $id, 'collection_id' => $collection]);
            }
        }

        $this->on();
    }

    private function on(bool $on = true): void
    {
        $on ? app(SiteSettingsRepository::class)->set('catalog.collections_enabled', '1') : app(SiteSettingsRepository::class)->forget('catalog.collections_enabled');
        $this->app->forgetScopedInstances();
    }

    private function context(int $id): ProductContext
    {
        $this->app->forgetScopedInstances();
        ProductContext::forgetShared();

        return ProductContext::fromPage(app(CatalogReader::class)->product("p{$id}") ?? $this->fail("no product p{$id}"));
    }

    /** @param  array<string, mixed>  $conditions */
    private function holds(int $id, array $conditions): bool
    {
        return ProductConditions::matches($this->context($id), $conditions);
    }

    public function test_in_collection_matches_only_the_products_of_the_listed_collections(): void
    {
        $this->assertTrue($this->holds(1, ['collections' => [1]]));
        $this->assertTrue($this->holds(2, ['collections' => [1]]));
        $this->assertFalse($this->holds(3, ['collections' => [1]]));
        $this->assertFalse($this->holds(4, ['collections' => [1]]));
        // OR inside the list
        $this->assertTrue($this->holds(3, ['collections' => [1, 2]]));
        $this->assertFalse($this->holds(1, ['collections' => [2, 3]]));
        // AND with the other kinds
        $this->assertFalse($this->holds(1, ['collections' => [1], 'on_sale' => true]));
        $this->assertTrue($this->holds(1, ['collections' => [1], 'on_sale' => false]));
    }

    public function test_while_off_the_condition_is_false_and_on_again_restores_it(): void
    {
        $this->on(false);
        $this->assertFalse($this->holds(1, ['collections' => [1]]), 'OFF: a block conditioned on a collection is hidden');
        $this->assertFalse($this->holds(1, ['collections' => [1], 'on_sale' => false]));
        $this->assertTrue($this->holds(1, ['on_sale' => false]), 'a block with no collection condition is untouched');

        $this->on();
        $this->assertTrue($this->holds(1, ['collections' => [1]]));
    }

    public function test_the_predicate_is_the_blades_too(): void
    {
        $predicate = ProductConditions::predicates()['in_collection'];
        $this->assertTrue($predicate($this->context(3), [[2]]));
        $this->assertFalse($predicate($this->context(3), [1]));
    }

    public function test_validation_checks_the_ids_the_cap_and_the_stored_value_survives_off(): void
    {
        [$clean, $errors] = ProductConditions::validate(['collections' => [2, 1, 1]]);
        $this->assertSame([], $errors);
        $this->assertSame(['collections' => [1, 2]], $clean);

        [, $errors] = ProductConditions::validate(['collections' => [999]]);
        $this->assertArrayHasKey('collections', $errors);

        [, $errors] = ProductConditions::validate(['collections' => range(1, ProductConditions::MAX_COLLECTIONS + 1)], false);
        $this->assertArrayHasKey('collections', $errors);

        DB::table('catalog_collections')->where('id', 3)->exists() || $this->fail();
        $this->assertSame([1, 3], ProductConditions::formState(['collections' => [1, 3, 777]])['collections'], 'an id that does not exist any more is dropped');
        $this->assertSame([1, 2], ProductConditions::fromForm(['collections' => [1, 2]])['collections']);
    }

    public function test_the_lazy_read_costs_nothing_without_a_conditioned_block_and_one_query_with_one(): void
    {
        $context = $this->context(1);
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });

        // the other predicates never touch the collection pivot
        ProductConditions::matches($context, ['on_sale' => false, 'brands' => [1]]);
        $this->assertSame(0, $n, 'no collection read for a block without a collection condition');

        ProductConditions::matches($context, ['collections' => [1]]);
        ProductConditions::matches($context, ['collections' => [2]]);
        $this->assertSame(1, $n, 'ONE grouped read, memoised, switch inside the statement (no settings query)');
    }
}
