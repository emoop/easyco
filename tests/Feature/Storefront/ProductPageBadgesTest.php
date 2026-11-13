<?php

namespace Tests\Feature\Storefront;

use App\Services\FreeShippingThreshold;
use App\Services\ShippingMethodInput;
use App\Services\ShippingMethodWriter;
use App\Services\ShippingZoneWriter;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Badges\BadgeSet;
use App\Storefront\Badges\BadgeStore;
use App\Storefront\Conditions\ProductContext;
use App\Storefront\ReadModels\CatalogSubjectKind;
use EasyCo\Pricing\Money;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * P7-3: the badges on the PRODUCT PAGE: the same published set, the same resolver and the same facts as the cards. OFF is today's page byte for byte (the fixture `product_html_before_badges.json` was captured from the
 * unchanged views, in three shapes); ON, `top` and `image_bottom` are an overlay over the main gallery picture (or in the placeholder of a product with no picture), `bottom` is a row right after the price. The badges
 * belong to the PRODUCT (never to a chosen variation); the page's conditions context is built once by the reader and shared.
 */
class ProductPageBadgesTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->showcase();
        ProductContext::forgetShared();
        $this->app->forgetScopedInstances();
    }

    /** the three shapes of the OFF fixture: a simple product with an image, a variable product, a product with no image */
    private function showcase(): void
    {
        $acme = $this->makeBrand(1, 'Acme', 'acme');
        $this->makeAttribute(1, 'Size', ['S', 'M']);

        $this->makeProduct(1, ['name' => 'Summer dress', 'slug' => 'summer-dress', 'brand' => $acme, 'price' => '100.00', 'sale' => '79.90', 'stock' => 4, 'short' => 'Light and airy']);
        $this->makeImage(1, 1, 0, alt: 'Front view');
        $this->makeProduct(2, [
            'type' => 'variable', 'name' => 'Linen shirt', 'slug' => 'linen-shirt', 'brand' => $acme,
            'variations' => [['attrs' => [1 => 'S'], 'price' => '40.00', 'stock' => 0], ['attrs' => [1 => 'M'], 'price' => '45.00', 'stock' => 3]],
        ]);
        $this->makeImage(2, 2, 0, alt: 'Shirt');
        $this->makeProduct(3, ['name' => 'Plain thing', 'slug' => 'plain-thing', 'price' => '20.00', 'stock' => 2]);
    }

    /** @return array<string, string> the page HTML of the three shapes */
    private function pages(): array
    {
        $out = [];

        foreach (['simple with image' => 'summer-dress', 'variable' => 'linen-shirt', 'no image' => 'plain-thing'] as $name => $slug) {
            $out[$name] = $this->page($slug);
        }

        return $out;
    }

    private function page(string $slug): string
    {
        $this->app->forgetScopedInstances();

        return $this->get('/product/'.$slug)->assertOk()->getContent();
    }

    /** @param array<string, array<string, mixed>> $over rows by kind, merged over the defaults @param list<array<string, mixed>> $custom */
    private function publish(array $over = [], array $custom = []): void
    {
        $working = BadgeSet::defaults();

        foreach ($working['badges'] as $i => $row) {
            $working['badges'][$i] = array_replace($row, $over[$row['kind']] ?? []);
        }

        array_push($working['badges'], ...$custom);
        [$clean, $errors] = BadgeSet::validate($working);
        $this->assertSame([], $errors, json_encode($errors, JSON_UNESCAPED_UNICODE));
        BadgeStore::publish($clean);
        $this->app->forgetScopedInstances();
    }

    /** @return array<string, mixed> */
    private function customRow(string $id, string $text, array $conditions, array $o = []): array
    {
        return array_replace(['kind' => 'custom', 'id' => $id, 'enabled' => true, 'text' => $text, 'position' => 'top', 'colour' => 'blue', 'opacity' => 100, 'shape' => 'square', 'priority' => 50, 'conditions' => $conditions], $o);
    }

    /** the labels of one position of a page, in print order */
    private function labels(string $html, string $position): array
    {
        $found = preg_match('#<ul class="sf-cbadges sf-cbadges--'.$position.' sf-cbadges--pdp">(.*?)</ul>#s', $html, $m);

        return $found === 1 ? array_map('html_entity_decode', (array) (preg_match_all('#<li class="sf-cbadge[^"]*">(.*?)</li>#s', $m[1], $l) ? $l[1] : [])) : [];
    }

    private function shipping(?int $freeAbove, bool $freeMethod = false): void
    {
        app(SiteSettingsRepository::class)->set('site.country', 'BG');
        $zone = (string) app(ShippingZoneWriter::class)->create('Bulgaria', ['BG'], [], [])->id();

        if ($freeAbove !== null) {
            app(ShippingMethodWriter::class)->create($zone, new ShippingMethodInput(name: 'Courier', kind: 'flat', price: Money::fromMinorUnits(500, 'EUR'), freeAbove: Money::fromMinorUnits($freeAbove, 'EUR')));
        }

        if ($freeMethod) {
            app(ShippingMethodWriter::class)->create($zone, new ShippingMethodInput(name: 'Free', kind: 'free'));
        }
    }

    // ================================================================ OFF

    /** ★ OFF: the product page is byte for byte what it was before the badges (the fixture was captured from the unchanged views); a published set with everything disabled is the same */
    public function test_with_no_badge_the_page_html_is_exactly_todays(): void
    {
        $before = json_decode((string) file_get_contents(base_path('tests/Fixtures/storefront/product_html_before_badges.json')), true);

        foreach ($this->pages() as $name => $html) {
            $this->assertSame($before[$name], $html, "page {$name}");
            $this->assertStringNotContainsString('sf-cbadge', $html);
            $this->assertStringNotContainsString('sf-gal-badges', $html);
        }

        $this->publish([BadgeSet::SALE => ['enabled' => true]]);
        $this->publish();
        $this->assertNull(app(SiteSettingsRepository::class)->get(\App\Settings\AppearanceSettings::BADGES_KEY));

        foreach ($this->pages() as $name => $html) {
            $this->assertSame($before[$name], $html, "page {$name} after everything was switched off again");
        }
    }

    // ================================================================ the built-ins, in their places

    /** ★ «Промо» (top), «New» (over the picture, at the bottom), «Последни бройки» and «Безплатна доставка» (under the price): each in its own place on the page */
    public function test_each_built_in_sits_in_its_place(): void
    {
        DB::table('catalog_products')->where('id', 1)->update(['created_at' => now()]);
        $this->shipping(5000);
        $this->publish([
            BadgeSet::SALE => ['enabled' => true, 'mode' => 'percent'],
            BadgeSet::NEW => ['enabled' => true, 'position' => 'image_bottom'],
            BadgeSet::LOW_STOCK => ['enabled' => true, 'max' => 6],
            BadgeSet::FREE_SHIPPING => ['enabled' => true],
        ]);

        $html = $this->page('summer-dress');

        $this->assertSame(['−20%'], $this->labels($html, 'top'), 'the percent comes from the price block the page prints (100.00 → 79.90)');
        $this->assertSame(['New'], $this->labels($html, 'image-bottom'));
        $this->assertSame(['Last items', 'Free shipping'], $this->labels($html, 'bottom'), 'stock 4 is within 1..6; 79.90 is above 50.00');

        // the markup: the overlay is inside the gallery stage, after the arrow and before the thumbnails; the bottom row is in the info column right after the price
        $this->assertMatchesRegularExpression('#class="sf-gal-next"[^>]*>.*?</button><div class="sf-gal-badges"><ul class="sf-cbadges sf-cbadges--top sf-cbadges--pdp">.*?</ul><ul class="sf-cbadges sf-cbadges--image-bottom sf-cbadges--pdp">.*?</ul></div>\s*</div>\s*<div class="sf-gal-thumbs">#s', $html);
        $this->assertGreaterThan(strpos($html, '<section class="sf-summary">'), strpos($html, 'sf-cbadges--bottom sf-cbadges--pdp'), 'the bottom row is in the info column');
        $this->assertGreaterThan(strpos($html, 'class="sf-price"'), strpos($html, 'sf-cbadges--bottom sf-cbadges--pdp'), 'and after the price');
        $this->assertSame(1, substr_count($html, 'sf-gal-badges'), 'one overlay: the badges are not repeated per thumbnail');
    }

    /** ★ the same caps and order as the cards: 2 on top, 1 over the picture at the bottom, all kinds together, priority then kind order */
    public function test_the_caps_and_the_order_are_the_cards(): void
    {
        DB::table('catalog_products')->where('id', 1)->update(['created_at' => now()]);
        $this->publish(
            [BadgeSet::SALE => ['enabled' => true, 'priority' => 30], BadgeSet::NEW => ['enabled' => true, 'priority' => 20], BadgeSet::LOW_STOCK => ['enabled' => true, 'position' => 'top', 'priority' => 10, 'max' => 6]],
            [$this->customRow('aaaaaa0001', 'Own', ['on_sale' => true], ['priority' => 5, 'position' => 'image_bottom']), $this->customRow('aaaaaa0002', 'Second', ['on_sale' => true], ['priority' => 6, 'position' => 'image_bottom'])],
        );

        $html = $this->page('summer-dress');

        $this->assertSame(['Last items', 'New'], $this->labels($html, 'top'), 'priority 10 and 20; priority 30 is dropped');
        $this->assertSame(['Own'], $this->labels($html, 'image-bottom'), 'the third position holds ONE');
    }

    /** ★ free shipping on the page: the page's LOWEST price (a variable product: 40.00) against the threshold, at / below; never with a FREE method, never with no threshold; OFF never reads the cache */
    public function test_free_shipping_on_the_page(): void
    {
        $this->publish([BadgeSet::FREE_SHIPPING => ['enabled' => true]]);
        $this->assertSame([], $this->labels($this->page('linen-shirt'), 'bottom'), 'no shipping configured: no threshold');

        $this->shipping(4500);
        $this->assertSame(['Free shipping'], $this->labels($this->page('linen-shirt'), 'bottom'), 'the page\'s lowest price is the AVAILABLE variation\'s 45.00 (the 40.00 one is sold out), exactly AT the threshold');
        $this->assertSame([], $this->labels($this->page('plain-thing'), 'bottom'), '20.00 is below');

        app(FreeShippingThreshold::class)->refresh();
        Cache::forever(FreeShippingThreshold::KEY, ['minor' => 4501, 'currency' => 'EUR']);
        $this->assertSame([], $this->labels($this->page('linen-shirt'), 'bottom'), 'one cent below');

        Cache::forever(FreeShippingThreshold::KEY, ['minor' => 100, 'currency' => 'USD']);
        $this->assertSame([], $this->labels($this->page('linen-shirt'), 'bottom'), 'another currency');

        app(ShippingMethodWriter::class)->create((string) DB::table('shipping_zones')->value('id'), new ShippingMethodInput(name: 'Free', kind: 'free'));
        $this->assertSame([], $this->labels($this->page('linen-shirt'), 'bottom'), 'an active FREE method: free for everyone');

        $reads = [];
        Event::listen([CacheHit::class, CacheMissed::class], function ($event) use (&$reads): void {
            $event->key === FreeShippingThreshold::KEY && $reads[] = $event::class;
        });
        $this->publish([BadgeSet::SALE => ['enabled' => true]]);
        $this->page('linen-shirt');
        $this->assertSame([], $reads, 'OFF: the page never touches the threshold');
    }

    // ================================================================ custom badges

    private function customFixture(): void
    {
        $now = '2026-03-01 10:00:00';
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeCategory(2, 'Dresses', 'dresses', 1);
        $this->makeCategory(3, 'Shoes', 'shoes');
        $this->makeTag(1, 'Eco', 'eco');
        DB::table('catalog_collections')->insert(['id' => 1, 'name' => 'Beach', 'slug' => 'beach', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('catalog_product_categories')->insert(['product_id' => 1, 'category_id' => 2]);
        DB::table('catalog_product_tags')->insert(['product_id' => 1, 'tag_id' => 1]);
        DB::table('catalog_product_collections')->insert(['product_id' => 1, 'collection_id' => 1]);
    }

    /** ★ a custom badge follows its condition on the page (through the page's own context); the collections switch hides a collections badge; the text is escaped */
    public function test_a_custom_badge_follows_its_condition(): void
    {
        $this->customFixture();

        foreach (['categories' => ['categories' => [1]], 'tags' => ['tags' => [1]], 'brand' => ['brands' => [1]], 'on sale' => ['on_sale' => true]] as $name => $conditions) {
            $this->publish([], [$this->customRow('aaaaaa0001', 'Special', $conditions)]);

            $this->assertSame(['Special'], $this->labels($this->page('summer-dress'), 'top'), $name);
        }

        $this->publish([], [$this->customRow('aaaaaa0001', 'Special', ['categories' => [3]])]);
        $this->assertSame([], $this->labels($this->page('summer-dress'), 'top'), 'another category: the condition does not hold');

        $this->publish([], [$this->customRow('aaaaaa0001', 'Beach', ['collections' => [1]])]);
        $this->assertSame([], $this->labels($this->page('summer-dress'), 'top'), 'collections OFF: false, the badge is hidden');
        app(SiteSettingsRepository::class)->set(CatalogSubjectKind::COLLECTIONS_SWITCH, '1');
        $this->assertSame(['Beach'], $this->labels($this->page('summer-dress'), 'top'), 'collections ON');

        $this->publish([], [$this->customRow('aaaaaa0001', '<b>Hot</b> & "new"', ['on_sale' => true])]);
        $html = $this->page('summer-dress');
        $this->assertStringContainsString('&lt;b&gt;Hot&lt;/b&gt; &amp; &quot;new&quot;', $html);
        $this->assertStringNotContainsString('<b>Hot', $html);
    }

    // ================================================================ no picture, sold out, variations, markup

    /** ★ a product with no picture still shows its top and image_bottom badges, in the placeholder (no longer aria-hidden); a sold-out product keeps its label and may carry badges */
    public function test_no_picture_and_sold_out(): void
    {
        $this->publish([BadgeSet::SALE => ['enabled' => true], BadgeSet::NEW => ['enabled' => true, 'position' => 'image_bottom'], BadgeSet::LOW_STOCK => ['enabled' => true]]);
        DB::table('catalog_products')->where('id', 3)->update(['created_at' => now()]);
        $this->makeProduct(4, ['name' => 'Sale thing', 'slug' => 'sale-thing', 'price' => '20.00', 'sale' => '10.00', 'stock' => 0]);
        DB::table('catalog_products')->where('id', 4)->update(['created_at' => now()]);

        $html = $this->page('plain-thing');
        $this->assertStringContainsString('<div class="sf-gal-placeholder sf-image-placeholder"><ul class="sf-cbadges sf-cbadges--image-bottom sf-cbadges--pdp">', $html, 'inside the placeholder, which holds a badge: not aria-hidden');
        $this->assertSame(['New'], $this->labels($html, 'image-bottom'));
        $this->assertSame(['Last items'], $this->labels($html, 'bottom'), 'stock 2');

        $sold = $this->page('sale-thing');
        $this->assertSame(['Sale'], $this->labels($sold, 'top'), 'sold out: the badges do not hide');
        $this->assertSame([], $this->labels($sold, 'bottom'), 'but «Последни бройки» is 1..N, never 0');
        $this->assertStringContainsString('<div class="sf-gal-placeholder sf-image-placeholder">', $sold);
    }

    /** ★ the badges belong to the PRODUCT: the variations' markup is the same ON and OFF, nothing in it depends on a badge (no script, no data attribute) */
    public function test_the_badges_are_decided_for_the_product_not_a_variation(): void
    {
        $off = $this->page('linen-shirt');
        $this->publish([BadgeSet::SALE => ['enabled' => true], BadgeSet::LOW_STOCK => ['enabled' => true, 'max' => 6]]);
        $on = $this->page('linen-shirt');

        $this->assertSame([], $this->labels($on, 'top'), 'the product has no discount');
        $this->assertSame(['Last items'], $this->labels($on, 'bottom'), 'the TOTAL stock of the shown variations (0 + 3)');

        $strip = static fn (string $html): string => preg_replace('#<div class="sf-gal-badges">.*?</div>#s', '', (string) preg_replace('#<ul class="sf-cbadges.*?</ul>#s', '', $html));
        $this->assertSame($strip($off), $strip($on), 'only the badge lists differ: the variation picker is the same');
        $this->assertStringNotContainsString('data-badge', $on);
        $this->assertDoesNotMatchRegularExpression('#<(?:ul|li)[^>]*(?:style|onclick|data-)#', (string) preg_replace('#<ul class="sf-card-sizes".*?</ul>#s', '', (string) substr($on, (int) strpos($on, '<ul class="sf-cbadges'), 400)));
    }

    /** ★ not in the structured data or the meta description; no style, no script */
    public function test_the_badges_are_not_in_the_meta_or_structured_data(): void
    {
        $this->publish([BadgeSet::SALE => ['enabled' => true, 'text' => 'Zzbadge']]);
        $html = $this->page('summer-dress');

        $this->assertStringContainsString('Zzbadge', $html);
        $this->assertStringNotContainsString('application/ld+json', $html);
        $this->assertSame(1, preg_match('#<meta name="description" content="([^"]*)"#', $html, $m));
        $this->assertStringNotContainsString('Zzbadge', $m[1]);
        $this->assertStringNotContainsString('Zzbadge', (string) preg_replace('#<ul class="sf-cbadges.*?</ul>#s', '', $html), 'it appears only in the badge list');
        $this->assertSame(0, preg_match('#<(?:ul|li)[^>]*\sstyle=#', $html));
    }

    // ================================================================ statements

    /** @return int the statements of ONE real request of the page (everything warmed by a first request) */
    private function statements(string $slug): int
    {
        $this->page($slug);
        ProductContext::forgetShared();
        $this->app->forgetScopedInstances();
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        $this->get('/product/'.$slug)->assertOk();

        return $n;
    }

    /**
     * ★ the page costs the same with the built-ins ON (warm caches) as OFF; custom badges add only what their conditions need, from the ONE context: categories 2 (the pivot and the tree), tags 1, collections 1; the
     * brand, the season and «on sale» ride on the product row and the price block (0). The numbers are pinned.
     */
    public function test_the_page_statements_are_pinned(): void
    {
        $this->customFixture();
        app(SiteSettingsRepository::class)->set(CatalogSubjectKind::COLLECTIONS_SWITCH, '1');
        $this->shipping(4000);

        $off = $this->statements('summer-dress');

        $this->publish([BadgeSet::SALE => ['enabled' => true], BadgeSet::NEW => ['enabled' => true], BadgeSet::LOW_STOCK => ['enabled' => true, 'max' => 6], BadgeSet::FREE_SHIPPING => ['enabled' => true]]);
        $builtIns = $this->statements('summer-dress');
        $this->assertSame($off, $builtIns, 'the four built-ins add NO statement (the warm cache, the created_at on the product row, the stock map the page has)');

        $this->publish([], [$this->customRow('aaaaaa0001', 'Brand', ['brands' => [1]]), $this->customRow('aaaaaa0002', 'Sale', ['on_sale' => true])]);
        $this->assertSame($off, $this->statements('summer-dress'), 'brand and «on sale» cost nothing');

        $this->publish([], [$this->customRow('aaaaaa0001', 'Cat', ['categories' => [1]]), $this->customRow('aaaaaa0002', 'Tag', ['tags' => [1]]), $this->customRow('aaaaaa0003', 'Col', ['collections' => [1]])]);
        $custom = $this->statements('summer-dress');
        fwrite(STDERR, "\n[query-count] P7-3 product page: OFF {$off}, built-ins {$builtIns}, custom (categories + tags + collections) {$custom} (+".($custom - $off).")\n");

        $this->assertSame(4, $custom - $off, 'categories 2 + tags 1 + collections 1');

        // only the kinds a custom row uses are read
        $this->publish([], [$this->customRow('aaaaaa0001', 'Tag', ['tags' => [1]])]);
        $this->assertSame(1, $this->statements('summer-dress') - $off, 'a tags badge reads the tags only');
    }
}
