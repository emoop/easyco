<?php

namespace Tests\Feature\Sandbox;

use App\Filament\Resources\ProductResource;
use App\Services\ProductPriceRangeProvider;
use App\Services\ProductTimelinePromoter;
use DateTimeImmutable;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
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
 * The sandbox list page — prompt D, D4, plus D3's product-visibility rule
 * and D7's noindex contract.
 *
 * FIXTURES ARE BUILT THROUGH THE REAL DOMAIN (Product::createSimple()/
 * publish()/setCatalogVisibility(), App\Services\ProductTimelinePromoter
 * for the timeline position, real PriceLists/PriceListItems for prices) —
 * never by writing columns directly. That matters here more than usual:
 * the sandbox's whole job is to render what the real domain considers
 * true, so a fixture that bypassed the domain could prove the page works
 * for data the domain would never produce.
 */
final class SandboxProductListTest extends TestCase
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
            // 0% tax so gross() equals the input decimal exactly — these
            // assertions are about literal display strings.
            price: Price::inclusiveOfTax(Money::fromDecimal($decimal, 'EUR'), 0),
        ));
    }

    /**
     * A product in whatever D3 state the caller asks for. $timelineAt is an
     * explicit timeline position, set through the real
     * ProductTimelinePromoter (never a raw column write) — and it is
     * RELATIVE ('+2 days'), because Product::promote() refuses any instant
     * before the product's own createdAt, a real domain rule these fixtures
     * hit on the first run.
     */
    private function product(
        string $name,
        ?string $regularPrice = null,
        ?string $salesPrice = null,
        string $status = 'active',
        string $visibility = 'visible',
        ?string $timelineAt = null,
    ): ProductModel {
        $suffix = (string) ++self::$counter;

        $product = Product::createSimple($name, "SKU-SB-{$suffix}", "sb-{$suffix}");
        $product->setCatalogVisibility(CatalogVisibility::from($visibility));

        match ($status) {
            'active' => $product->publish(),
            'archived' => $product->archive(),
            default => $product->markAsDraft(),
        };

        app(ProductRepository::class)->save($product);

        if ($timelineAt !== null) {
            app(ProductTimelinePromoter::class)->promote((string) $product->id(), new DateTimeImmutable($timelineAt));
        }

        $priceableId = (string) $product->universalVariation()->priceableId();

        if ($regularPrice !== null) {
            $this->addPriceItem('Regular Prices', $priceableId, $regularPrice);
        }

        if ($salesPrice !== null) {
            $this->addPriceItem('Manual Sale', $priceableId, $salesPrice);
        }

        return ProductModel::findOrFail($product->id());
    }

    public function test_the_list_contains_only_active_and_catalog_visible_products(): void
    {
        $this->seedPricingLists();

        $this->product('Listed Active', '10.00');
        $this->product('Not Listed Draft', '11.00', status: 'draft');
        $this->product('Not Listed Archived', '12.00', status: 'archived');
        $this->product('Not Listed Hidden', '13.00', visibility: 'hidden');

        $response = $this->get('/_sandbox')->assertOk();

        $response->assertSee('Listed Active');
        $response->assertDontSee('Not Listed Draft');
        $response->assertDontSee('Not Listed Archived');
        $response->assertDontSee('Not Listed Hidden');

        // The count line is the page's own statement of the same fact — a
        // second, independent check that nothing was filtered late.
        $response->assertSee('1 product visible to a customer');
    }

    public function test_the_list_is_ordered_newest_first_by_the_product_timeline(): void
    {
        $this->seedPricingLists();

        $this->product('Oldest Product', '10.00', timelineAt: '+1 day');
        $this->product('Middle Product', '10.00', timelineAt: '+2 days');
        $this->product('Newest Product', '10.00', timelineAt: '+3 days');

        $this->get('/_sandbox')
            ->assertOk()
            ->assertSeeInOrder(['Newest Product', 'Middle Product', 'Oldest Product']);
    }

    public function test_the_list_paginates_at_24_products_per_page(): void
    {
        $this->seedPricingLists();

        // 25 listed products, one minute apart: index 0 is the OLDEST and
        // index 24 the newest — so the newest-first page 1 holds 24..1 and
        // page 2 holds only index 0. The base is relative ('+1 day') because
        // Product::promote() refuses a timeline before the product's own
        // createdAt.
        for ($i = 0; $i < 25; $i++) {
            $this->product(
                "Sandbox P {$i}",
                '10.00',
                timelineAt: (new DateTimeImmutable('+1 day'))->modify("+{$i} minutes")->format('Y-m-d H:i:s')
            );
        }

        $firstPage = $this->get('/_sandbox')->assertOk();
        $firstPage->assertSee('Sandbox P 24');
        $firstPage->assertDontSee('Sandbox P 0');
        $firstPage->assertSee('Page 1 of 2');
        $firstPage->assertSee('25 products visible to a customer');

        $secondPage = $this->get('/_sandbox?page=2')->assertOk();
        $secondPage->assertSee('Sandbox P 0');
        $secondPage->assertSee('Page 2 of 2');
    }

    public function test_the_sandbox_price_is_the_same_string_the_admin_shows_for_that_product(): void
    {
        $this->seedPricingLists();

        $product = $this->product('Priced Product', '80.00', '60.00');

        // The admin's own path, called exactly as its table column calls it.
        $adminHtml = ProductResource::priceRangeHtml(
            app(ProductPriceRangeProvider::class)->forProduct((string) $product->id)
        );

        $this->assertSame('<s>80.00 €</s> 60.00 €', $adminHtml);

        $this->get('/_sandbox')
            ->assertOk()
            ->assertSee($adminHtml, false);
    }

    public function test_the_page_sends_the_noindex_header_and_contains_the_noindex_meta(): void
    {
        $this->seedPricingLists();
        $this->product('Indexable Never', '10.00');

        $response = $this->get('/_sandbox')->assertOk();

        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        $response->assertSee('<meta name="robots" content="noindex">', false);
    }
}
