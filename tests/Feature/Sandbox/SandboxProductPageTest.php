<?php

namespace Tests\Feature\Sandbox;

use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Contracts\VariationMediaRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\ProductMedia;
use EasyCo\Media\VariationMedia;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Pricing\Seeders\PricingSystemListsSeeder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sandbox product page — prompt D, D5, plus D3's variation-visibility
 * rule, D7's noindex contract and D8's read-only posture.
 *
 * Everything is asserted against the REAL RENDERED HTML of the real route,
 * with real values (prices resolved by Pricing, stock by Inventory,
 * purchasable by the Catalog domain) — never against a view model built
 * for the test.
 *
 * CELL-LEVEL ASSERTIONS ('<td>37</td>', '<td>Yes</td>') rather than a
 * substring search for '37' or 'Yes': those bare values appear elsewhere
 * in any HTML page by accident, and the whole point of the variations
 * table is which CELL carries which value. The price cell is asserted as
 * '<td>—</td>' for the same reason.
 */
final class SandboxProductPageTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;

    /**
     * PUBLIC, matching Illuminate\Foundation\Testing\TestCase's own
     * (public) signature — narrowing it to protected is a fatal error.
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

    private function seedPricingLists(): void
    {
        app(PricingSystemListsSeeder::class)->run(app(PriceListRepository::class));
    }

    private function addPriceItem(string $listName, string $targetId, string $decimal): void
    {
        $list = app(PriceListRepository::class)->findSystemListByName($listName);

        app(PriceListItemRepository::class)->save(new PriceListItem(
            id: null,
            priceListId: $list->id(),
            targetType: PriceListItemTargetType::VARIATION,
            targetId: $targetId,
            price: Price::inclusiveOfTax(Money::fromDecimal($decimal, 'EUR'), 0),
        ));
    }

    /**
     * A SIMPLE product with the UNIVERSAL variation Product::createSimple()
     * creates alongside it — the fixture the SIMPLE branch of D3 needs.
     *
     * $stock writes a real Inventory row through StockLevelRepository and
     * $purchasable=false flips the variation's own is_purchasable flag
     * BEFORE the product is saved, so what the page reports comes from the
     * domain's own rule (status ACTIVE + is_purchasable) and from the real
     * Inventory read, never from anything this test computes.
     */
    private function simpleProduct(
        string $slug,
        ?string $regularPrice = null,
        string $status = 'active',
        string $visibility = 'visible',
        ?int $stock = null,
        bool $purchasable = true,
    ): ProductModel {
        $product = Product::createSimple("Product {$slug}", 'SKU-'.strtoupper($slug), $slug);
        $product->setCatalogVisibility(CatalogVisibility::from($visibility));

        if (! $purchasable) {
            $product->universalVariation()?->setPurchasable(false);
        }

        match ($status) {
            'active' => $product->publish(),
            'archived' => $product->archive(),
            default => $product->markAsDraft(),
        };

        app(ProductRepository::class)->save($product);

        if ($regularPrice !== null) {
            $this->addPriceItem('Regular Prices', (string) $product->universalVariation()->priceableId(), $regularPrice);
        }

        if ($stock !== null) {
            app(StockLevelRepository::class)->save(
                StockLevel::forVariation((string) $product->universalVariation()->priceableId(), $stock)
            );
        }

        return ProductModel::findOrFail($product->id());
    }

    /**
     * A VARIABLE product with one declared axis ('Size') and one STANDARD
     * variation per spec — the same domain construction path
     * ProductResourcePriceColumnTest::variableProduct() already
     * establishes, extended with the visibility/stock fields D5 asserts.
     *
     * @param array<int, array{label: string, sku: string, status?: string, visible?: bool, purchasable?: bool, regular?: ?string, stock?: ?int}> $specs
     */
    private function variableProduct(string $slug, array $specs): ProductModel
    {
        $definition = new AttributeDefinition(id: null, code: "axis-{$slug}", name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $values = [];

        foreach ($specs as $spec) {
            $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: $spec['label']);
            app(AttributeValueRepository::class)->save($value);
            $values[] = $value;
        }

        $product = Product::createVariable("Product {$slug}", 'SKU-'.strtoupper($slug), $slug);
        $product->setCatalogVisibility(CatalogVisibility::VISIBLE);
        $product->declareVariationAxes([new VariationAxis($definition, $values)]);

        foreach (array_values($specs) as $i => $spec) {
            $variation = $product->addStandardVariation([$definition->id() => $values[$i]->id()], $spec['sku']);

            // setVisible()/setPurchasable() BEFORE the status transition, so
            // an archived variation's own archive() is what finally decides
            // its flags — exactly what the domain's own order implies.
            if (($spec['visible'] ?? true) === false) {
                $variation->setVisible(false);
            }

            if (($spec['purchasable'] ?? true) === false) {
                $variation->setPurchasable(false);
            }

            match ($spec['status'] ?? 'active') {
                'active' => $variation->activate(),
                'archived' => $variation->archive(),
                default => null, // DRAFT — as addStandardVariation() already created it
            };
        }

        $product->publish();
        app(ProductRepository::class)->save($product);

        foreach (array_values($product->variations()) as $i => $variation) {
            $spec = array_values($specs)[$i];

            if (($spec['regular'] ?? null) !== null) {
                $this->addPriceItem('Regular Prices', (string) $variation->priceableId(), $spec['regular']);
            }

            if (($spec['stock'] ?? null) !== null) {
                app(StockLevelRepository::class)->save(
                    StockLevel::forVariation((string) $variation->priceableId(), $spec['stock'])
                );
            }
        }

        return ProductModel::findOrFail($product->id());
    }

    /**
     * A real MediaAsset attached to a product, in whatever processing state
     * the caller needs — the states the gallery must actually discriminate
     * between (the dev database really does contain ready, failed and video
     * rows, see SandboxCatalogReader::firstImagePathSubquery()'s docblock).
     *
     * $state applies to IMAGE assets only: a VIDEO asset is created READY by
     * MediaAsset::create() itself (media-domain-design.md §4 — no pipeline
     * for video), and markProcessing() would throw for one by design
     * (assertNotVideo()).
     */
    private function attachProductMedia(string $productId, string $path, MediaType $type, string $state, ?string $altText = null): void
    {
        $asset = MediaAsset::create($type, 'public', $path, $altText);

        if ($type === MediaType::IMAGE) {
            if ($state === 'ready') {
                $asset->markProcessing();
                $asset->markReady([]);
            } elseif ($state === 'failed') {
                $asset->markProcessing();
                $asset->markFailed('deliberately failed by the test fixture');
            }
        }

        app(MediaAssetRepository::class)->save($asset);

        app(ProductMediaRepository::class)->save(new ProductMedia(
            id: null,
            productId: $productId,
            mediaId: (string) $asset->id(),
            sortOrder: 0,
            autoplay: false,
        ));
    }

    private function attachVariationMedia(string $variationId, string $path): void
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', $path, 'Variation photo');
        $asset->markProcessing();
        $asset->markReady([]);
        app(MediaAssetRepository::class)->save($asset);

        app(VariationMediaRepository::class)->save(new VariationMedia(
            id: null,
            variationId: $variationId,
            mediaId: (string) $asset->id(),
            sortOrder: 0,
        ));
    }

    public function test_the_variations_table_shows_attributes_price_stock_and_purchasable_state(): void
    {
        $this->seedPricingLists();

        $product = $this->variableProduct('table', [
            ['label' => 'M', 'sku' => 'SKU-V-M', 'regular' => '12.00', 'stock' => 37],
            ['label' => 'L', 'sku' => 'SKU-V-L', 'purchasable' => false, 'stock' => 91],
        ]);

        $response = $this->get('/_sandbox/products/'.$product->id)->assertOk();

        // Axis name AND value, straight from the domain's own
        // attributeAssignments() — not from the pivot rows.
        $response->assertSee('Size: M');
        $response->assertSee('Size: L');

        // The priced, purchasable variation.
        $response->assertSee('SKU-V-M');
        $response->assertSee('<td>12.00 €</td>', false);
        $response->assertSee('<td>37</td>', false);
        $response->assertSee('<td>Yes</td>', false);

        // The exact same row shape for a variation that is active and
        // visible but NOT purchasable, with NO configured price at all.
        $response->assertSee('SKU-V-L');
        $response->assertSee('<td>—</td>', false);
        $response->assertSee('<td>91</td>', false);
        $response->assertSee('<td>No</td>', false);

        // Product-level range: one resolvable quote is uniform, so it
        // renders as the plain amount (no 'from' prefix).
        $response->assertSee('<p class="price">12.00 €</p>', false);
    }

    public function test_draft_archived_and_invisible_variations_are_excluded(): void
    {
        $this->seedPricingLists();

        $product = $this->variableProduct('excluded', [
            ['label' => 'M', 'sku' => 'SKU-SHOWN', 'regular' => '10.00'],
            ['label' => 'L', 'sku' => 'SKU-DRAFTED', 'status' => 'draft'],
            ['label' => 'XL', 'sku' => 'SKU-ARCHIVED', 'status' => 'archived'],
            ['label' => 'XXL', 'sku' => 'SKU-INVISIBLE', 'visible' => false],
        ]);

        $response = $this->get('/_sandbox/products/'.$product->id)->assertOk();

        $response->assertSee('SKU-SHOWN');
        $response->assertDontSee('SKU-DRAFTED');
        $response->assertDontSee('SKU-ARCHIVED');
        $response->assertDontSee('SKU-INVISIBLE');
    }

    public function test_a_product_with_no_resolvable_price_anywhere_shows_a_dash_not_an_error(): void
    {
        $this->seedPricingLists();

        $product = $this->variableProduct('unpriced', [
            ['label' => 'M', 'sku' => 'SKU-UNPRICED', 'stock' => 3],
        ]);

        $response = $this->get('/_sandbox/products/'.$product->id)->assertOk();

        $response->assertSee('SKU-UNPRICED');

        // Exactly one '<td>—</td>' in the whole document: the variation's
        // own price cell. (The product-level '—' is a <p class="price">.)
        $this->assertSame(1, substr_count($response->getContent(), '<td>—</td>'));
        $response->assertSee('<p class="price">—</p>', false);
    }

    /**
     * D3's SIMPLE branch, as decided in review (D3's original wording,
     * "status = ACTIVE and is_visible", cannot apply to a SIMPLE product:
     * Variation's own constructor forces isVisible = false for a UNIVERSAL
     * variation and Variation::setVisible(true) throws a LogicException for
     * one — "The Universal variation is never customer-selectable —
     * enforced here, not just by convention").
     *
     * So a SIMPLE product's page shows NO variations table — there is
     * nothing to select between — and instead shows its universal
     * variation's stock quantity and purchasable state under the price, on
     * status = ACTIVE alone. No "no variation a customer could currently
     * see" message either: that message belongs to a VARIABLE product with
     * nothing listed.
     *
     * Whole lines are asserted rather than bare values ('37' can appear in
     * a page by accident; '<p class="stock">Stock: 37</p>' cannot) — the
     * same cell-level discipline the variations-table assertions above use.
     */
    public function test_a_simple_product_shows_its_universal_variations_stock_and_purchasable_state_under_the_price(): void
    {
        $this->seedPricingLists();

        $product = $this->simpleProduct('universal', '19.99', stock: 37);

        $response = $this->get('/_sandbox/products/'.$product->id)->assertOk();

        $response->assertSee('<p class="price">19.99 €</p>', false);
        $response->assertSee('<p class="stock">Stock: 37</p>', false);
        $response->assertSee('<p class="purchasable">Purchasable: Yes</p>', false);

        $response->assertDontSee('<table>', false);
        $response->assertDontSee('<h2>Variations</h2>', false);
        $response->assertDontSee('This product has no variation a customer could currently see');
    }

    /**
     * The other half of the SIMPLE branch: purchasability is the DOMAIN's
     * own answer (Variation::isEffectivelyPurchasable() — status ACTIVE AND
     * is_purchasable), not "yes, because the product is listed". This
     * variation has is_purchasable = false and no stock row at all, so the
     * page must render 'No' and a real 0 — StockLevelRepository's contract
     * says "no row" and "zero stock" are the same fact, never a null.
     */
    public function test_a_simple_products_universal_variation_can_report_not_purchasable_and_zero_stock(): void
    {
        $this->seedPricingLists();

        $product = $this->simpleProduct('not-purchasable', '19.99', purchasable: false);

        $response = $this->get('/_sandbox/products/'.$product->id)->assertOk();

        $response->assertSee('<p class="stock">Stock: 0</p>', false);
        $response->assertSee('<p class="purchasable">Purchasable: No</p>', false);

        $response->assertDontSee('<table>', false);
    }

    public function test_the_gallery_shows_product_images_first_then_variation_images_and_skips_the_rest(): void
    {
        $this->seedPricingLists();

        $product = $this->variableProduct('gallery', [
            ['label' => 'M', 'sku' => 'SKU-G-M', 'regular' => '10.00'],
        ]);

        $variationId = (string) $product->variations()->value('id');

        // A VIDEO asset is genuinely READY (MediaAsset::create() decides
        // that by type — no pipeline exists for video), so it is skipped
        // for being a video, not for being unready. The pending and failed
        // images are real, distinct states the dev database also contains.
        $this->attachProductMedia((string) $product->id, 'products/gallery-product.jpg', MediaType::IMAGE, 'ready');
        $this->attachProductMedia((string) $product->id, 'products/gallery-video.mp4', MediaType::VIDEO, 'ready');
        $this->attachProductMedia((string) $product->id, 'products/gallery-pending.jpg', MediaType::IMAGE, 'pending');
        $this->attachProductMedia((string) $product->id, 'products/gallery-failed.jpg', MediaType::IMAGE, 'failed');
        $this->attachVariationMedia($variationId, 'products/gallery-variation.jpg');

        $response = $this->get('/_sandbox/products/'.$product->id)->assertOk();

        $productImageUrl = app(MediaStorageAdapter::class)->url('public', 'products/gallery-product.jpg');
        $variationImageUrl = app(MediaStorageAdapter::class)->url('public', 'products/gallery-variation.jpg');

        $response->assertSee($productImageUrl, false);
        $response->assertSee($variationImageUrl, false);

        // D5's own order: product media, then variation media.
        $response->assertSeeInOrder([$productImageUrl, $variationImageUrl]);

        $response->assertDontSee('gallery-video.mp4');
        $response->assertDontSee('gallery-pending.jpg');
        $response->assertDontSee('gallery-failed.jpg');

        // No alt text on the asset -> the product name, never alt="".
        $response->assertSee('alt="Product gallery"', false);
    }

    public function test_a_product_that_is_not_listed_returns_404_and_still_sends_noindex(): void
    {
        $this->seedPricingLists();

        $draft = $this->simpleProduct('draft-product', '10.00', status: 'draft');
        $archived = $this->simpleProduct('archived-product', '10.00', status: 'archived');
        $hidden = $this->simpleProduct('hidden-product', '10.00', visibility: 'hidden');

        foreach ([$draft->id, $archived->id, $hidden->id] as $productId) {
            $this->get('/_sandbox/products/'.$productId)->assertNotFound();
        }

        // A genuinely nonexistent id behaves identically — the page never
        // discloses which of the two happened (D3's own wording).
        $this->get('/_sandbox/products/999999')->assertNotFound();

        // The rendered 404 is returned by the controller, not thrown, so
        // the group's middleware still applies the header (see
        // NoIndexHeaders' own docblock on why that distinction matters).
        $notFound = $this->get('/_sandbox/products/'.$hidden->id);
        $notFound->assertNotFound();
        $this->assertSame('noindex', $notFound->headers->get('X-Robots-Tag'));
    }

    public function test_the_product_page_sends_the_noindex_header_and_contains_the_noindex_meta(): void
    {
        $this->seedPricingLists();

        $product = $this->simpleProduct('noindex-check', '10.00');

        $response = $this->get('/_sandbox/products/'.$product->id)->assertOk();

        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        $response->assertSee('<meta name="robots" content="noindex">', false);
        $response->assertSee('SANDBOX — not the real storefront', false);
    }
}
