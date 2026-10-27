<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * GL-3.3: the product page's grid structure. The gallery and the summary sit inside `div.sf-product-main` (the two-column grid that keeps the sticky
 * summary inside its own box); the description is a direct child of `article.sf-product`, outside that wrapper, so the sticky summary never runs over it.
 * Checked on the DOM (XPath), with and without photos.
 */
class ProductPageStructureTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;

        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeProduct(1, ['name' => 'Summer dress', 'slug' => 'summer-dress', 'price' => '10.00', 'stock' => 1, 'description' => '<p>Long description</p>']);
    }

    private function xpath(string $html): \DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    /** XPath predicate: the element carries the class `$class` as one of its words. */
    private function hasClass(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    }

    private function page(): \DOMXPath
    {
        $this->app->forgetScopedInstances();
        $response = $this->get('/product/summer-dress');
        $response->assertOk();

        return $this->xpath($response->getContent());
    }

    private function assertStructure(\DOMXPath $xpath): void
    {
        $article = "//article[{$this->hasClass('sf-product')}]";
        $wrapper = "{$article}/div[{$this->hasClass('sf-product-main')}]";

        $this->assertSame(1, $xpath->query($article)->length, 'exactly one product article');
        $this->assertSame(1, $xpath->query($wrapper)->length, 'exactly one sf-product-main, a direct child of the article');

        $this->assertSame(1, $xpath->query("{$wrapper}/section[{$this->hasClass('sf-gallery')}]")->length, 'the gallery is a direct child of the wrapper');
        $this->assertSame(1, $xpath->query("{$wrapper}/section[{$this->hasClass('sf-summary')}]")->length, 'the summary is a direct child of the wrapper');
        $this->assertSame(2, $xpath->query("{$wrapper}/*")->length, 'the wrapper holds ONLY the gallery and the summary');

        $this->assertSame(1, $xpath->query("{$article}/section[{$this->hasClass('sf-description')}]")->length, 'the description is a direct child of the article');
        $this->assertSame(0, $xpath->query("{$wrapper}//section[{$this->hasClass('sf-description')}]")->length, 'the description is NOT inside the wrapper (the sticky summary would run over it)');
    }

    public function test_with_photos_the_gallery_and_the_summary_share_the_wrapper_and_the_description_stays_outside(): void
    {
        $this->makeImage(1, 1, 1);
        $this->makeImage(2, 1, 2);

        $this->assertStructure($this->page());
    }

    public function test_without_photos_the_structure_is_the_same(): void
    {
        $this->assertStructure($this->page());
    }

    public function test_a_product_without_a_description_has_the_wrapper_and_no_description_section(): void
    {
        $this->makeProduct(2, ['name' => 'Plain', 'slug' => 'plain', 'price' => '10.00', 'stock' => 1]);
        $this->makeImage(3, 2, 1);

        $xpath = $this->xpath($this->get('/product/plain')->assertOk()->getContent());

        $this->assertSame(1, $xpath->query("//article[{$this->hasClass('sf-product')}]/div[{$this->hasClass('sf-product-main')}]")->length);
        $this->assertSame(0, $xpath->query("//section[{$this->hasClass('sf-description')}]")->length);
    }
}
