<?php

namespace Tests\Feature;

use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Contracts\PriceListScopeRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Enums\PriceListScopeType;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Pricing\PriceListScope;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P5, Part A: the cart READ (GET /api/cart) is proven byte-identical across the refactor that replaced the per-line reads by grouped ones, and its query count no longer grows with the number of lines.
 *
 * The golden files (tests/Fixtures/cart/p5-*) were captured on the code BEFORE the refactor (P5_WRITE_GOLDEN=1) and must stay green after it. They are the cart JSON of a fixed cart of ten lines (simple and variable
 * products; a brand-scoped percentage list; a flash-sale fixed-item list; a line at its stock limit; a line whose stock ran out; a line whose price was removed; a line whose price changed since it was added;
 * a promotion with a total usage limit) for a GUEST, and of a small ACCOUNT cart with a new-customers-only code, normalised only for the ids that are random (variation ids, cart id). The one thing the refactor ADDS
 * is `max_quantity` on a line (the cart page's quantity control already reads it; the server never sent it): it is left out of the comparison and asserted on its own.
 *
 * The cart page (GET /cart) is a static shell filled from the JSON: its HTML is pinned too.
 */
class CartReadCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;

    private ?PriceList $regular = null;

    /** @var list<string> the variation ids in the order the lines were added, for the normalisation */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost/');
        $this->withoutVite();
        // Livewire injects its styles and script into any page once a Livewire component was rendered earlier in the same PHP process (a full run): that is another test's leftover, not the cart page, so it is switched off here
        config(['livewire.inject_assets' => false]);
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    private function simple(string $price, int $stock = 50): string
    {
        self::$counter++;
        $n = self::$counter;
        $product = Product::createSimple("P5 Product {$n}", "P5-{$n}", "p5-product-{$n}");
        app(ProductRepository::class)->save($product);
        $id = $product->variations()[0]->id();
        $this->setPrice($id, $price);
        app(StockLevelRepository::class)->save(StockLevel::forVariation($id, $stock));

        return $id;
    }

    private function variable(string $price, int $stock = 50): string
    {
        self::$counter++;
        $n = self::$counter;
        $definition = new AttributeDefinition(id: null, code: "p5-size-{$n}", name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'M');
        app(AttributeValueRepository::class)->save($value);
        $product = Product::createVariable("P5 Variable {$n}", "P5-VAR-{$n}", "p5-variable-{$n}");
        $product->setCatalogVisibility(CatalogVisibility::VISIBLE);
        $product->declareVariationAxes([new VariationAxis($definition, [$value])]);
        $variation = $product->addStandardVariation([$definition->id() => $value->id()], "P5-VAR-{$n}-M");
        $variation->activate();
        $product->publish();
        app(ProductRepository::class)->save($product);
        $this->setPrice($variation->id(), $price);
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variation->id(), $stock));

        return $variation->id();
    }

    private function setPrice(string $variationId, string $decimal): void
    {
        if ($this->regular === null) {
            $this->regular = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->regular);
        }

        app(PriceListItemRepository::class)->save(new PriceListItem(null, $this->regular->id(), PriceListItemTargetType::VARIATION, $variationId, Price::exclusiveOfTax(Money::fromDecimal($decimal, 'EUR'), 0)));
    }

    private function removePrices(string $variationId): void
    {
        DB::table('pricing_price_list_items')->where('target_id', $variationId)->delete();
    }

    private function add(string $variationId, int $quantity): string
    {
        $this->ids[] = $variationId;

        return (string) $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => $quantity])->assertStatus(201)->json('cart_id');
    }

    private function brandedProduct(string $variationId, string $slug): void
    {
        $product = app(ProductRepository::class)->findById(app(VariationRepository::class)->findById($variationId)->productId());
        $brand = new Brand(id: null, name: "Brand {$slug}", slug: $slug);
        app(BrandRepository::class)->save($brand);
        $product->assignBrand($brand->id());
        app(ProductRepository::class)->save($product);

        $list = PriceList::create("{$slug} -20%", PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 10, percentageBasisPoints: 2000);
        app(PriceListRepository::class)->save($list);
        app(PriceListScopeRepository::class)->attach(new PriceListScope(id: null, priceListId: $list->id(), scopeType: PriceListScopeType::BRAND, scopeReferenceId: $brand->id()));
    }

    private function flashSale(string $variationId, string $price): void
    {
        $list = PriceList::create('Flash Sale', PriceListMode::FIXED_ITEMS, priority: 5);
        app(PriceListRepository::class)->save($list);
        app(PriceListItemRepository::class)->save(new PriceListItem(null, $list->id(), PriceListItemTargetType::VARIATION, $variationId, Price::exclusiveOfTax(Money::fromDecimal($price, 'EUR'), 0)));
    }

    /** Ten lines of a guest cart, with one of every case; returns nothing, the cart is in the session. */
    private function buildGuestCart(): void
    {
        $a = $this->simple('10.00');
        $this->add($a, 2);
        $b = $this->simple('25.50');
        $this->add($b, 1);
        $c = $this->variable('40.00');
        $this->add($c, 3);
        $d = $this->simple('30.00');
        $this->brandedProduct($d, 'p5-nike');
        $this->add($d, 1);
        $e = $this->simple('20.00');
        $this->add($e, 2);
        $this->flashSale($e, '12.00');
        $f = $this->simple('8.00', 3);
        $this->add($f, 3);
        $g = $this->simple('9.00', 5);
        $this->add($g, 1);
        DB::table('stock_levels')->where('variation_id', $g)->update(['quantity' => 0]);
        $h = $this->simple('11.00');
        $this->add($h, 1);
        $this->removePrices($h);
        $i = $this->simple('15.00');
        $this->add($i, 1);
        $this->removePrices($i);
        $this->setPrice($i, '17.00');
        $j = $this->simple('5.00');
        $this->add($j, 1);

        app(PromotionRepository::class)->save(Promotion::create(code: 'p5-ten', discountType: PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 1000, minimumSpend: Money::fromDecimal('50.00', 'EUR'), usageLimitTotal: 100));
        $this->putJson('/api/cart/promotion', ['code' => 'p5-ten'])->assertStatus(200);
    }

    // ---- normalising ---------------------------------------------------------------------------------------------

    /** the cart JSON with the random ids replaced by their position, `max_quantity` taken out (it is new) @return array<string, mixed> */
    private function normalised(array $cart): array
    {
        $names = [];

        foreach ($this->ids as $position => $id) {
            $names[(string) $id] = 'V'.($position + 1);
        }

        $out = $cart;
        $out['cart_id'] = 'CART';

        foreach ($out['lines'] ?? [] as $i => $line) {
            $out['lines'][$i]['variation_id'] = $names[(string) $line['variation_id']] ?? 'UNKNOWN';
            unset($out['lines'][$i]['max_quantity']);
        }

        if (isset($out['promotion']['applicable_variation_ids'])) {
            $out['promotion']['applicable_variation_ids'] = array_map(static fn ($id): string => $names[(string) $id] ?? 'UNKNOWN', $out['promotion']['applicable_variation_ids']);
        }

        return $out;
    }

    private function golden(string $name, string $content): void
    {
        $path = base_path('tests/Fixtures/cart/'.$name);

        if (getenv('P5_WRITE_GOLDEN') === '1') {
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $content);
        }

        $this->assertFileExists($path, 'the golden file is captured on the code before the refactor (P5_WRITE_GOLDEN=1)');
        $this->assertSame(str_replace("\r\n", "\n", (string) file_get_contents($path)), $content, $name);
    }

    // ---- the characterization ------------------------------------------------------------------------------------

    /** ★ the guest cart of ten lines: the JSON is byte-identical to the one the code printed before the grouped reads */
    public function test_the_ten_line_guest_cart_json_is_identical(): void
    {
        $this->buildGuestCart();

        $cart = $this->getJson('/api/cart')->assertOk()->json();

        $this->assertCount(10, $cart['lines']);
        $this->golden('p5-guest-cart.json', json_encode($this->normalised($cart), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }

    /** ★ an ACCOUNT cart with a new-customers-only code and a per-customer limit (the usage context is read for the account) */
    public function test_the_account_cart_json_is_identical(): void
    {
        $account = Account::register('p5-char@example.com', 'hashed-password');
        app(AccountRepository::class)->save($account);
        $this->actingAs(AccountModel::findOrFail($account->id()), 'customer');

        $a = $this->simple('60.00');
        $this->add($a, 1);
        $b = $this->variable('35.00');
        $this->add($b, 2);
        $c = $this->simple('4.50');
        $this->add($c, 4);
        app(PromotionRepository::class)->save(Promotion::create(code: 'p5-new', discountType: PromotionDiscountType::FIXED_AMOUNT, discountAmount: Money::fromDecimal('5.00', 'EUR'), newCustomersOnly: true, usageLimitPerCustomer: 2));
        $this->putJson('/api/cart/promotion', ['code' => 'p5-new'])->assertStatus(200);

        $cart = $this->getJson('/api/cart')->assertOk()->json();

        $this->assertCount(3, $cart['lines']);
        $this->golden('p5-account-cart.json', json_encode($this->normalised($cart), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }

    /** the cart page is a static shell: the same bytes whatever the cart holds */
    public function test_the_cart_page_html_is_identical(): void
    {
        $html = $this->get('/cart')->assertOk()->getContent();

        $this->golden('p5-cart-page.html', (string) $html);
    }

    /** ★ `max_quantity` of a line = the stock capped at the storefront's 99: the number the add and the update already enforce */
    public function test_max_quantity_is_the_stock_capped_at_the_storefront_limit(): void
    {
        $a = $this->simple('10.00', 250);
        $this->add($a, 1);
        $b = $this->simple('10.00', 7);
        $this->add($b, 2);
        $c = $this->simple('10.00', 4);
        $this->add($c, 4);
        $d = $this->simple('10.00', 6);
        $this->add($d, 1);
        DB::table('stock_levels')->where('variation_id', $d)->update(['quantity' => 0]);

        $lines = $this->getJson('/api/cart')->assertOk()->json('lines');

        $this->assertSame([99, 7, 4, 0], array_column($lines, 'max_quantity'));
    }

    // ---- the query count -----------------------------------------------------------------------------------------

    /** @return int the queries of ONE GET /api/cart of a cart of $lineCount lines */
    private function readQueries(int $lineCount): int
    {
        $this->flushSession();
        $this->ids = [];

        for ($i = 0; $i < $lineCount; $i++) {
            $this->add($i % 3 === 0 ? $this->variable('10.00') : $this->simple('10.00'), 1);
        }

        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        $this->getJson('/api/cart')->assertOk();
        DB::flushQueryLog();

        return $n;
    }

    /** ★ the same cart read costs the same with 1, 10 and 30 lines (a small constant); one query put back inside the line loop turns this red */
    public function test_the_cart_read_does_not_grow_with_the_number_of_lines(): void
    {
        $one = $this->readQueries(1);
        $ten = $this->readQueries(10);
        $thirty = $this->readQueries(30);

        fwrite(STDERR, "\n[query-count] GET /api/cart: 1 line {$one}, 10 lines {$ten}, 30 lines {$thirty}\n");

        $this->assertLessThanOrEqual($one + 2, $ten, '10 lines must not cost more than 1 line (+2)');
        $this->assertLessThanOrEqual($one + 2, $thirty, '30 lines must not cost more than 1 line (+2)');
        $this->assertLessThanOrEqual(40, $thirty, 'a hard ceiling for the whole cart read');
    }
}
