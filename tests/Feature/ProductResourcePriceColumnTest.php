<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\StaffPanelUser;
use App\Services\ProductPriceRangeProvider;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Pricing\Seeders\PricingSystemListsSeeder;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises ProductResource's table price column — now backed by
 * EasyCo\Pricing\PriceRange, resolved for the whole page in one batch
 * via App\Services\ProductPriceRangeProvider, rendered via
 * ProductResource::priceRangeHtml(). The old priceDisplayHtml()/
 * priceMinorSubquery() pair is gone entirely; the SIMPLE-product
 * fixture helpers mirror ProductPricingAndStockTest's own established
 * shapes.
 */
class ProductResourcePriceColumnTest extends TestCase
{
    use RefreshDatabase;

    private function staffWithRole(string $roleName): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName($roleName);
        }

        $email = strtolower(str_replace(' ', '.', $roleName)).'@example.com';
        $staff = Staff::create($email, app(PasswordHasher::class)->hash('password123'), $roleName, $role);
        app(StaffRepository::class)->save($staff);

        return StaffPanelUser::find($staff->id());
    }

    private function actingAsStaffRole(string $roleName): StaffPanelUser
    {
        $model = $this->staffWithRole($roleName);
        $this->actingAs($model, 'staff');
        session()->forget('password_hash_staff');

        return $model;
    }

    private function createSimpleProduct(string $name, string $slug, array $overrides = []): ProductModel
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(array_merge([
                'name' => $name,
                'slug' => $slug,
                'base_sku' => 'SKU-'.strtoupper($slug),
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
            ], $overrides))
            ->call('create')
            ->assertHasNoFormErrors();

        return ProductModel::where('slug', $slug)->firstOrFail();
    }

    private function priceRangeHtmlFor(ProductModel $record): string
    {
        $priceRange = app(ProductPriceRangeProvider::class)->forProduct((string) $record->id);

        return ProductResource::priceRangeHtml($priceRange);
    }

    public function test_a_product_with_only_a_regular_price_shows_it_plainly(): void
    {
        app(PricingSystemListsSeeder::class)->run(app(PriceListRepository::class));
        $this->actingAsStaffRole('Administrator');

        $this->createSimpleProduct('Air Max', 'air-max', ['regular_price' => '29.99']);

        $row = Livewire::test(ListProducts::class)
            ->instance()
            ->getTable()
            ->getRecords()
            ->firstWhere('slug', 'air-max');

        // Only the symbol is new versus the pre-Prompt-B behaviour —
        // '29.99' -> '29.99 €' (PriceDisplayFormatter's own suffix
        // format, EUR being the configured default currency).
        $this->assertSame('29.99 €', $this->priceRangeHtmlFor($row));
    }

    public function test_a_product_with_both_prices_shows_the_regular_price_struck_through_plus_the_sale_price(): void
    {
        app(PricingSystemListsSeeder::class)->run(app(PriceListRepository::class));
        $this->actingAsStaffRole('Administrator');

        $this->createSimpleProduct('Stan Smith', 'stan-smith', [
            'regular_price' => '80.00',
            'sale_price' => '60.00',
        ]);

        $row = Livewire::test(ListProducts::class)
            ->instance()
            ->getTable()
            ->getRecords()
            ->firstWhere('slug', 'stan-smith');

        // '<s>80.00</s> 60.00' -> '<s>80.00 €</s> 60.00 €' — only the
        // symbol is new, same struck-through shape as before.
        $this->assertSame('<s>80.00 €</s> 60.00 €', $this->priceRangeHtmlFor($row));
    }

    public function test_a_product_with_neither_price_shows_a_dash_not_an_error(): void
    {
        app(PricingSystemListsSeeder::class)->run(app(PriceListRepository::class));
        $this->actingAsStaffRole('Administrator');

        $this->createSimpleProduct('Gazelle', 'gazelle');

        $row = Livewire::test(ListProducts::class)
            ->instance()
            ->getTable()
            ->getRecords()
            ->firstWhere('slug', 'gazelle');

        // The old regular_price_minor column no longer exists at all —
        // the real state this rendering now relies on is an EMPTY
        // PriceRange, asserted directly via the provider.
        $priceRange = app(ProductPriceRangeProvider::class)->forProduct((string) $row->id);
        $this->assertTrue($priceRange->isEmpty());
        $this->assertSame('—', ProductResource::priceRangeHtml($priceRange));
    }

    public function test_multiple_products_each_resolve_their_own_correct_prices_in_the_same_list(): void
    {
        app(PricingSystemListsSeeder::class)->run(app(PriceListRepository::class));
        $this->actingAsStaffRole('Administrator');

        $this->createSimpleProduct('Ultraboost', 'ultraboost', ['regular_price' => '150.00']);
        $this->createSimpleProduct('Forum Low', 'forum-low', [
            'regular_price' => '90.00',
            'sale_price' => '70.00',
        ]);
        $this->createSimpleProduct('Samba', 'samba', ['regular_price' => '85.00']);

        $records = Livewire::test(ListProducts::class)
            ->instance()
            ->getTable()
            ->getRecords();

        $ranges = app(ProductPriceRangeProvider::class)->forProducts(
            $records->pluck('id')->map(fn ($id) => (string) $id)->all()
        );

        $this->assertSame('150.00 €', ProductResource::priceRangeHtml($ranges[(string) $records->firstWhere('slug', 'ultraboost')->id]));
        $this->assertSame('<s>90.00 €</s> 70.00 €', ProductResource::priceRangeHtml($ranges[(string) $records->firstWhere('slug', 'forum-low')->id]));
        $this->assertSame('85.00 €', ProductResource::priceRangeHtml($ranges[(string) $records->firstWhere('slug', 'samba')->id]));
    }

    public function test_a_product_on_a_fresh_unseeded_store_renders_the_list_without_error(): void
    {
        // Deliberately NOT running PricingSystemListsSeeder — neither
        // reserved system list exists, exactly the fresh-store case
        // this file's own former priceMinorSubquery() docblock used to
        // document; PriceRangeResolver::resolveQuotes() (D3 of Prompt A)
        // returns [] for the same condition, not a RuntimeException.
        $this->actingAsStaffRole('Administrator');

        $this->createSimpleProduct('Superstar', 'superstar');

        $response = Livewire::test(ListProducts::class);
        $response->assertOk();

        $row = $response->instance()->getTable()->getRecords()->firstWhere('slug', 'superstar');
        $this->assertSame('—', $this->priceRangeHtmlFor($row));
    }

    // ─────────────────────────────────────────────────────────────
    // VARIABLE product cases — each rendered through a real
    // Livewire::test(ListProducts::class), exercising the column's
    // real ->getStateUsing() closure (batching included), not
    // priceRangeHtml() called directly.
    // ─────────────────────────────────────────────────────────────

    private function seedPricingLists(): void
    {
        app(PricingSystemListsSeeder::class)->run(app(PriceListRepository::class));
    }

    private function addPriceItem(string $listName, PriceListItemTargetType $targetType, string $targetId, string $decimal): void
    {
        $list = app(PriceListRepository::class)->findSystemListByName($listName);
        $item = new PriceListItem(
            id: null,
            priceListId: $list->id(),
            targetType: $targetType,
            targetId: $targetId,
            // 0% tax so gross() equals the input decimal exactly —
            // these fixtures assert literal display strings.
            price: Price::inclusiveOfTax(Money::fromDecimal($decimal, 'EUR'), 0),
        );
        app(PriceListItemRepository::class)->save($item);
    }

    /**
     * Builds a VARIABLE product with one declared axis and one STANDARD
     * variation per given spec. $specs is a list of
     * ['regular' => ?string, 'sale' => ?string, 'archived' => bool].
     *
     * @param array<int, array{regular?: ?string, sale?: ?string, archived?: bool}> $specs
     */
    private function variableProduct(string $slug, array $specs): ProductModel
    {
        $definition = new AttributeDefinition(id: null, code: "axis-{$slug}", name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $values = [];
        foreach (array_keys($specs) as $i => $_) {
            $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: "V{$i}");
            app(AttributeValueRepository::class)->save($value);
            $values[] = $value;
        }

        $product = Product::createVariable("Product {$slug}", "SKU-{$slug}", $slug);
        $product->declareVariationAxes([new VariationAxis($definition, $values)]);

        $variations = [];
        foreach (array_values($specs) as $i => $spec) {
            $variation = $product->addStandardVariation([$definition->id() => $values[$i]->id()], "SKU-{$slug}-{$i}");
            $variation->activate();

            if ($spec['archived'] ?? false) {
                $variation->archive();
            }

            $variations[] = $variation;
        }

        app(ProductRepository::class)->save($product);

        foreach (array_values($specs) as $i => $spec) {
            if (($spec['regular'] ?? null) !== null) {
                $this->addPriceItem('Regular Prices', PriceListItemTargetType::VARIATION, $variations[$i]->id(), $spec['regular']);
            }
            if (($spec['sale'] ?? null) !== null) {
                $this->addPriceItem('Manual Sale', PriceListItemTargetType::VARIATION, $variations[$i]->id(), $spec['sale']);
            }
        }

        return ProductModel::find($product->id());
    }

    private function assertColumnState(string $expectedHtml, ProductModel $record): void
    {
        Livewire::test(ListProducts::class)
            ->assertTableColumnStateSet('price_display', $expectedHtml, $record);
    }

    public function test_variable_product_level_price_only(): void
    {
        $this->seedPricingLists();
        $this->actingAsStaffRole('Administrator');

        $definition = new AttributeDefinition(id: null, code: 'axis-lvl', name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'M');
        app(AttributeValueRepository::class)->save($value);

        $product = Product::createVariable('Product Level', 'SKU-LEVEL', 'product-level');
        $product->declareVariationAxes([new VariationAxis($definition, [$value])]);
        $variation = $product->addStandardVariation([$definition->id() => $value->id()], 'SKU-LEVEL-0');
        $variation->activate();
        app(ProductRepository::class)->save($product);

        // PRODUCT-level item, not VARIATION-level — this is exactly the
        // case the old priceMinorSubquery() (VARIATION-only) could
        // never resolve, hence the "—" defect this task fixes.
        $this->addPriceItem('Regular Prices', PriceListItemTargetType::PRODUCT, $product->id(), '29.99');

        $this->assertColumnState('29.99 €', ProductModel::find($product->id()));
    }

    public function test_two_variations_with_equal_prices_show_a_single_price_no_prefix(): void
    {
        $this->seedPricingLists();
        $this->actingAsStaffRole('Administrator');

        $record = $this->variableProduct('equal-prices', [
            ['regular' => '49.99'],
            ['regular' => '49.99'],
        ]);

        $this->assertColumnState('49.99 €', $record);
    }

    public function test_different_prices_show_the_lowest_prefixed_with_from(): void
    {
        $this->seedPricingLists();
        $this->actingAsStaffRole('Administrator');

        $record = $this->variableProduct('different-prices', [
            ['regular' => '49.99'],
            ['regular' => '69.99'],
        ]);

        $this->assertColumnState(e(__('products.price_from')).' 49.99 €', $record);
    }

    public function test_sale_on_every_variation_shows_a_single_struck_through_price(): void
    {
        $this->seedPricingLists();
        $this->actingAsStaffRole('Administrator');

        $record = $this->variableProduct('sale-every', [
            ['regular' => '69.99', 'sale' => '49.99'],
            ['regular' => '69.99', 'sale' => '49.99'],
        ]);

        $this->assertColumnState('<s>69.99 €</s> 49.99 €', $record);
    }

    public function test_sale_only_on_the_cheapest_variation(): void
    {
        $this->seedPricingLists();
        $this->actingAsStaffRole('Administrator');

        $record = $this->variableProduct('sale-cheapest', [
            ['regular' => '69.99', 'sale' => '49.99'],
            ['regular' => '59.99'],
        ]);

        $this->assertColumnState(e(__('products.price_from')).' <s>69.99 €</s> 49.99 €', $record);
    }

    /**
     * A discount elsewhere in the range must NOT force a struck-through
     * display when the WINNING (lowest-final) quote itself isn't
     * discounted. NOTE: the task's own illustrative numbers for this
     * case ("49.99 plain and 69.99 -> 39.99") are internally
     * inconsistent with its own stated expected output — 39.99 would be
     * the objectively lowest final AND discounted, so PriceRange's own
     * documented "lowest final wins" rule would correctly show
     * '<s>69.99 €</s> 39.99 €' with no "от", not the stated
     * 'от 49.99 €' with no <s>. Substituted with numbers that actually
     * produce the stated output: the discounted variation's final
     * (59.99) stays ABOVE the plain variation's price (49.99), so the
     * discount never becomes the winning quote.
     */
    public function test_discount_on_the_non_cheapest_variation_does_not_add_a_strike_through(): void
    {
        $this->seedPricingLists();
        $this->actingAsStaffRole('Administrator');

        $record = $this->variableProduct('discount-non-cheapest', [
            ['regular' => '49.99'],
            ['regular' => '69.99', 'sale' => '59.99'],
        ]);

        $this->assertColumnState(e(__('products.price_from')).' 49.99 €', $record);
    }

    public function test_equal_finals_with_different_regulars_assert_the_deterministic_winner(): void
    {
        $this->seedPricingLists();
        $this->actingAsStaffRole('Administrator');

        // Both finals are 49.99: variation 0's is a discounted 79.99,
        // variation 1's is plain 49.99 (regular === final, no
        // discount). Tie-break: lowest regular wins -> variation 1
        // (49.99 < 79.99) — its own quote is NOT discounted, so no <s>;
        // finals are equal, so hasUniformFinalPrice() is true -> no
        // "от" prefix either.
        $record = $this->variableProduct('equal-finals', [
            ['regular' => '79.99', 'sale' => '49.99'],
            ['regular' => '49.99'],
        ]);

        $this->assertColumnState('49.99 €', $record);
    }

    public function test_nothing_priced_shows_a_dash(): void
    {
        $this->seedPricingLists();
        $this->actingAsStaffRole('Administrator');

        $record = $this->variableProduct('nothing-priced', [
            [],
            [],
        ]);

        $this->assertColumnState('—', $record);
    }

    public function test_an_archived_variations_cheaper_override_does_not_drag_the_range(): void
    {
        $this->seedPricingLists();
        $this->actingAsStaffRole('Administrator');

        $record = $this->variableProduct('archived-override', [
            ['regular' => '49.99'],
            ['regular' => '49.99'],
            ['regular' => '9.99', 'archived' => true],
        ]);

        $this->assertColumnState('49.99 €', $record);
    }

    public function test_partially_priced_covers_only_the_priced_variations(): void
    {
        $this->seedPricingLists();
        $this->actingAsStaffRole('Administrator');

        $record = $this->variableProduct('partially-priced', [
            ['regular' => '59.99'],
            ['regular' => '49.99'],
            ['regular' => '69.99'],
            [],
        ]);

        $this->assertColumnState(e(__('products.price_from')).' 49.99 €', $record);
    }

    // ─────────────────────────────────────────────────────────────
    // Query-count and non-table fallback path.
    // ─────────────────────────────────────────────────────────────

    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $callback();

        DB::flushQueryLog();

        return $count;
    }

    public function test_query_count_for_a_5_product_vs_25_product_page_is_equal(): void
    {
        $this->seedPricingLists();
        $this->actingAsStaffRole('Administrator');

        for ($i = 1; $i <= 25; $i++) {
            $this->variableProduct("qc-{$i}", [
                ['regular' => '19.99'],
                ['regular' => '29.99'],
            ]);
        }

        // Measures the SAME provider call the column's own
        // priceRangeForRecord() makes for a table-bearing $livewire —
        // real "5-product page" vs. "25-product page" query counts, not
        // a proxy measurement. A FRESH provider instance per
        // measurement (bypassing the scoped() container binding on
        // purpose) — the scoped() cache would otherwise make the
        // second, overlapping call artificially cheaper than a real
        // fresh page load ever is, corrupting exactly the comparison
        // this test exists to make.
        $allProductIds = ProductModel::orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();

        // Constructed directly, not via app() — a scoped() binding's
        // instance is still cached/reused across ordinary app() calls
        // within the same test; only a real new object bypasses that.
        $freshProvider = fn (): ProductPriceRangeProvider => new ProductPriceRangeProvider(
            app(\App\Services\CatalogScopeResolver::class),
            app(\EasyCo\Pricing\Contracts\PriceRangeResolver::class),
        );

        $queriesForFiveProducts = $this->countQueries(
            fn () => $freshProvider()->forProducts(array_slice($allProductIds, 0, 5))
        );
        $queriesForTwentyFiveProducts = $this->countQueries(
            fn () => $freshProvider()->forProducts($allProductIds)
        );

        fwrite(STDERR, "\n[query-count] 5-product page: {$queriesForFiveProducts} queries, 25-product page: {$queriesForTwentyFiveProducts} queries\n");

        $this->assertSame($queriesForFiveProducts, $queriesForTwentyFiveProducts, 'query count must not grow with the number of rows on the page');
    }

    /** The extracted priceRangeForRecord() fallback branch, exercised directly with a $livewire that is not table-bearing. */
    public function test_the_non_table_fallback_path_still_renders_correctly(): void
    {
        $this->seedPricingLists();
        $this->actingAsStaffRole('Administrator');

        $record = $this->variableProduct('fallback-path', [
            ['regular' => '39.99'],
        ]);

        $notTableBearing = new class
        {
            // Deliberately has no getTableRecords() method at all.
        };

        $priceRange = ProductResource::priceRangeForRecord($record, $notTableBearing);

        $this->assertSame('39.99 €', ProductResource::priceRangeHtml($priceRange));
    }
}
