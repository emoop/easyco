<?php

namespace Tests\Feature\Sandbox;

use App\Services\PaymentMethodAdapterResolver;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
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
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The sandbox storefront's stage-2 pages (D3's controls, D4/D5's pages and D6's
 * confirmation) as the real routes actually render them — never against a view
 * model built for the test.
 *
 * WHAT THIS CLASS COVERS, AND WHAT IT DELIBERATELY DOES NOT:
 * - the three new pages exist and render with the flag on, each carrying both
 *   halves of the noindex contract (SandboxRoutesDisabledTest proves the other
 *   half of D1's gate: with the flag off these paths 404 and the route names are
 *   absent from the router's table);
 * - the product page's add-to-cart controls follow the DOMAIN's own purchasable
 *   flag — one control for a SIMPLE product, one per purchasable variation for a
 *   VARIABLE one, and NONE where the API would refuse;
 * - the checkout page lists exactly the payment methods
 *   PaymentMethodAdapterResolver::availableMethods() declares, with no code
 *   hardcoded in the view;
 * - a guessed /_sandbox/order-placed/{id} is not a route at all.
 *
 * The cart and checkout pages are shells whose JavaScript talks to the real API
 * from a browser, so what can honestly be asserted server-side is asserted here
 * (they render, they are noindex, and they point at the real endpoints);
 * SandboxCheckoutFlowTest proves the API they call actually works end to end.
 */
final class SandboxStorefrontPagesTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;

    private ?PriceList $priceList = null;

    /**
     * PUBLIC, matching Illuminate\Foundation\Testing\TestCase's own (public)
     * signature — narrowing it to protected is a fatal error. The flag must be a
     * real process-environment value BEFORE the container is built, because the
     * sandbox's routes are registered once, at boot (see SandboxTestEnvironment).
     *
     * @return Application
     */
    public function createApplication()
    {
        SandboxTestEnvironment::enableSandboxFlag();

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        SandboxTestEnvironment::restoreSandboxFlag();
    }

    private function setPrice(string $variationId, string $decimalAmount): void
    {
        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        app(PriceListItemRepository::class)->save(new PriceListItem(
            null,
            $this->priceList->id(),
            PriceListItemTargetType::VARIATION,
            $variationId,
            Price::exclusiveOfTax(Money::fromDecimal($decimalAmount, 'EUR'), 0),
        ));
    }

    private function setStock(string $variationId, int $quantity): void
    {
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $quantity));
    }

    /**
     * A published, priced, stocked SIMPLE product, optionally with its universal
     * variation switched to not purchasable — the domain flag D3's rule reads.
     *
     * @return array{variation_id: string, name: string, sku: string}
     */
    private function simpleProduct(bool $purchasable = true): array
    {
        self::$counter++;
        $suffix = (string) self::$counter;
        $name = "Product {$suffix}";
        $sku = "SKU-{$suffix}";

        $product = Product::createSimple($name, $sku, "sandbox-simple-{$suffix}");
        $product->setCatalogVisibility(CatalogVisibility::VISIBLE);
        $variation = $product->variations()[0];

        if (! $purchasable) {
            $variation->setPurchasable(false);
        }

        $variation->activate();
        $product->publish();
        app(ProductRepository::class)->save($product);

        // Ids exist only after the aggregate is saved — never before.
        $variationId = (string) $product->variations()[0]->id();

        $this->setPrice($variationId, '10.00');
        $this->setStock($variationId, 5);

        return ['variation_id' => $variationId, 'name' => $name, 'sku' => $sku];
    }

    /**
     * A published VARIABLE product with one SELECT axis and one standard variation
     * per spec, each spec carrying its own purchasable flag.
     *
     * @param  array<int, array{value: string, purchasable: bool}>  $specs
     * @return array{variation_ids: array<int, string>, non_purchasable_id: ?string, product_id: string}
     */
    private function variableProduct(array $specs): array
    {
        self::$counter++;
        $suffix = (string) self::$counter;

        $definition = new AttributeDefinition(id: null, code: "size-{$suffix}", name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $values = [];
        foreach ($specs as $spec) {
            $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: $spec['value']);
            app(AttributeValueRepository::class)->save($value);
            $values[] = $value;
        }

        $product = Product::createVariable("Product {$suffix}", "SKU-VAR-{$suffix}", "sandbox-variable-{$suffix}");
        $product->setCatalogVisibility(CatalogVisibility::VISIBLE);
        $product->declareVariationAxes([new VariationAxis($definition, $values)]);

        $purchasableIds = [];
        $nonPurchasableId = null;

        foreach (array_values($specs) as $index => $spec) {
            $variation = $product->addStandardVariation(
                [$definition->id() => $values[$index]->id()],
                "SKU-VAR-{$suffix}-{$spec['value']}",
            );

            // setPurchasable() BEFORE the status transition, so activate() is what
            // finally decides the flags — the domain's own order, mirrored from
            // SandboxProductPageTest's own fixture.
            if (! $spec['purchasable']) {
                $variation->setPurchasable(false);
            }

            $variation->activate();
        }

        $product->publish();
        app(ProductRepository::class)->save($product);

        // Ids exist only after the aggregate is saved, so prices and stock are set
        // here, walking the saved variations in the order they were added.
        foreach (array_values($product->variations()) as $index => $variation) {
            if (array_values($specs)[$index]['purchasable']) {
                $purchasableIds[] = (string) $variation->id();
            } else {
                $nonPurchasableId = (string) $variation->id();
            }

            $this->setPrice((string) $variation->id(), '12.50');
            $this->setStock((string) $variation->id(), 4);
        }

        $product->publish();
        app(ProductRepository::class)->save($product);

        return [
            'variation_ids' => $purchasableIds,
            'non_purchasable_id' => $nonPurchasableId,
            'product_id' => (string) $product->id(),
        ];
    }

    private function assertNoIndex(string $body, $response): void
    {
        $this->assertStringContainsString(
            'noindex',
            (string) $response->headers->get('X-Robots-Tag'),
            'the X-Robots-Tag header must say noindex'
        );
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $body, 'the noindex meta must be present');
    }

    public function test_the_cart_page_renders_is_noindex_and_talks_to_the_real_cart_api(): void
    {
        $response = $this->get('/_sandbox/cart')->assertOk();

        $this->assertNoIndex($response->getContent(), $response);

        // The page is a shell: it holds no lines of its own and asks the real API
        // for them, so a cart is never identified by this page.
        $this->assertStringContainsString("'/api/cart'", $response->getContent());
        $this->assertStringContainsString("'/api/cart/promotion'", $response->getContent());
        $this->assertStringContainsString("'/api/cart/lines/'", $response->getContent());
    }

    public function test_the_checkout_page_renders_and_is_noindex(): void
    {
        $response = $this->get('/_sandbox/checkout')->assertOk();

        $this->assertNoIndex($response->getContent(), $response);
        $this->assertStringContainsString("'/api/checkout'", $response->getContent());

        // The page must name the cart it is confirming: POST /api/checkout REQUIRES
        // cart_id (cart-domain-design.md §14.2) — without it the API answers 422, and a
        // double-click could never be answered with the first order.
        $this->assertStringContainsString('cart_id', $response->getContent());
        $this->assertStringContainsString('place-order', $response->getContent());
    }

    public function test_the_order_confirmation_page_renders_and_is_noindex(): void
    {
        $response = $this->get('/_sandbox/order-placed')->assertOk();

        $this->assertNoIndex($response->getContent(), $response);

        // D6: it announces itself as browser-local rather than pretending to be a
        // server-rendered receipt, and it never calls an order endpoint.
        $this->assertStringContainsString('sessionStorage', $response->getContent());
        $this->assertStringNotContainsString("'/api/orders", $response->getContent());
    }

    public function test_the_product_pages_add_to_cart_controls_follow_the_domains_purchasable_flag(): void
    {
        $simple = $this->simpleProduct();
        $variable = $this->variableProduct([
            ['value' => 'M', 'purchasable' => true],
            ['value' => 'L', 'purchasable' => true],
            ['value' => 'XL', 'purchasable' => false],
        ]);
        $notPurchasable = $this->simpleProduct(purchasable: false);

        // SIMPLE: exactly one control — the universal variation is the whole product.
        $simpleHtml = $this->get(route('sandbox.products.show', ['productId' => $this->productIdOf($simple['variation_id'])]))
            ->assertOk()
            ->getContent();
        $this->assertSame(1, substr_count($simpleHtml, 'data-variation-id="'));
        $this->assertStringContainsString('data-variation-id="'.$simple['variation_id'].'"', $simpleHtml);

        // VARIABLE: one per PURCHASABLE variation, and none for the non-purchasable one.
        $variableHtml = $this->get(route('sandbox.products.show', ['productId' => $variable['product_id']]))
            ->assertOk()
            ->getContent();
        $this->assertSame(2, substr_count($variableHtml, 'data-variation-id="'));
        $this->assertStringContainsString('data-variation-id="'.$variable['variation_ids'][0].'"', $variableHtml);
        $this->assertStringContainsString('data-variation-id="'.$variable['variation_ids'][1].'"', $variableHtml);
        $this->assertStringNotContainsString('data-variation-id="'.$variable['non_purchasable_id'].'"', $variableHtml);

        // Not purchasable: no control at all, so the page cannot offer what the API refuses.
        $refusedHtml = $this->get(route('sandbox.products.show', ['productId' => $this->productIdOf($notPurchasable['variation_id'])]))
            ->assertOk()
            ->getContent();
        $this->assertSame(0, substr_count($refusedHtml, 'data-variation-id="'));
        $this->assertStringContainsString('Not purchasable right now', $refusedHtml);
    }

    public function test_the_checkout_page_lists_exactly_the_payment_methods_the_resolver_declares(): void
    {
        $methods = app(PaymentMethodAdapterResolver::class)->availableMethods();
        $html = $this->get('/_sandbox/checkout')->assertOk()->getContent();

        // Counted by the radio inputs themselves, not by the string
        // 'name="payment_method"' — the page's own JavaScript contains that
        // selector too, and the count must be about the rendered controls.
        $this->assertSame(count($methods), substr_count($html, 'type="radio" name="payment_method"'));
        $this->assertSame(count($methods), substr_count($html, 'data-payment-method="'));

        foreach ($methods as $method) {
            $this->assertStringContainsString('value="'.$method.'"', $html, "the checkout page must offer \"{$method}\"");
            // Rendered as readable words, derived from the code rather than a second
            // hardcoded list: 'cash_on_delivery' -> 'Cash on delivery'.
            $this->assertStringContainsString(ucfirst(str_replace('_', ' ', $method)), $html);
        }
    }

    public function test_a_guessed_order_placed_id_is_not_a_route_at_all(): void
    {
        $this->assertTrue(Route::has('sandbox.order-placed'));

        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString(
                'order-placed/',
                $route->uri(),
                'no sandbox route may take an order id — the confirmation page reads sessionStorage only'
            );
        }

        $this->get('/_sandbox/order-placed/1')->assertNotFound();
        $this->get('/_sandbox/order-placed/0193f0d0-0000-7000-8000-000000000000')->assertNotFound();
    }

    /** The product a variation belongs to — the sandbox page is addressed by product id. */
    private function productIdOf(string $variationId): string
    {
        return app(VariationRepository::class)->findById($variationId)->productId();
    }
}
