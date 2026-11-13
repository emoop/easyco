<?php

namespace Tests\Feature\Storefront;

use App\Services\FreeShippingThreshold;
use App\Services\PriceListWriter;
use App\Services\ShippingMethodInput;
use App\Services\ShippingMethodWriter;
use App\Services\ShippingZoneWriter;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\StoreTimezone;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Badges\BadgeResolver;
use App\Storefront\Badges\BadgeSet;
use App\Storefront\Badges\BadgeStore;
use App\Storefront\ReadModels\Badge;
use App\Storefront\ReadModels\ImageSet;
use App\Storefront\ReadModels\ListingQuery;
use App\Storefront\ReadModels\PriceBlock;
use App\Storefront\ReadModels\ProductCard;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\Conditions\ProductContext;
use App\Storefront\ReadModels\CatalogSubjectKind;
use DateTimeImmutable;
use EasyCo\Pricing\Money;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * P7-1: the built-in badges of the product card («Промо», «Нови», «Последни бройки»). OFF (the default, and a published set with everything disabled) is TODAY's card, byte for byte; ON costs no statement; each badge's rule
 * is its own (the percent comes from the printed price block, the new window counts store-local calendar days, the stock window is 1..N); at most two per position in priority order; the markup carries closed class
 * words and escaped text only.
 */
class CardBadgesTest extends TestCase
{
    use AssertsSafeHtml;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->showSoldOutProducts();
        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->seedPricing();
        app()->setLocale('bg');
        ProductContext::forgetShared();
    }

    /** The five cards the pre-change HTML fixture was taken from (the view before P7-1). @return array<string, ProductCard> */
    private function fixtureCards(): array
    {
        $image = static fn (string $i): ImageSet => new ImageSet("https://cdn.test/storage/p/{$i}-medium.webp", 'Front', 900, 675, [['url' => "https://cdn.test/storage/p/{$i}-thumbnail.webp", 'width' => 400], ['url' => "https://cdn.test/storage/p/{$i}-medium.webp", 'width' => 900]], '100vw');

        return [
            'full' => new ProductCard('1', 'summer-dress', '/product/summer-dress', 'Summer dress', ['name' => 'Acme', 'slug' => 'acme'], $image('1'), new PriceBlock(7990, 7990, 'EUR', 10000), [], true, $image('2')),
            'plain' => new ProductCard('2', 'hat', '/product/hat', 'Hat <b>', null, $image('3'), new PriceBlock(1000, 2000, 'EUR', null), [], true),
            'noimg_out' => new ProductCard('3', 'box', '/product/box', 'Box', null, null, new PriceBlock(500, 500, 'EUR', null), [], false),
            'noprice_out' => new ProductCard('4', 'x', '/product/x', 'X', null, null, null, [], false),
            'noprice_in' => new ProductCard('5', 'y', '/product/y', 'Y', null, null, null, [], true),
        ];
    }

    private function renderCard(ProductCard $card, bool $eager = false): string
    {
        return view('storefront.partials.card', ['card' => $card, 'eager' => $eager])->render();
    }

    /** @param array<string, array<string, mixed>> $over rows by kind, merged over the defaults */
    private function publish(array $over = []): void
    {
        $working = BadgeSet::defaults();

        foreach ($working['badges'] as $i => $row) {
            $working['badges'][$i] = array_replace($row, $over[$row['kind']] ?? []);
        }

        [$clean, $errors] = BadgeSet::validate($working);
        $this->assertSame([], $errors, json_encode($errors));
        BadgeStore::publish($clean);
        $this->app->forgetScopedInstances();
    }

    private function allOn(array $extra = []): void
    {
        $this->publish([
            BadgeSet::SALE => ['enabled' => true] + ($extra[BadgeSet::SALE] ?? []),
            BadgeSet::NEW => ['enabled' => true] + ($extra[BadgeSet::NEW] ?? []),
            BadgeSet::LOW_STOCK => ['enabled' => true] + ($extra[BadgeSet::LOW_STOCK] ?? []),
        ]);
    }

    /** @return list<ProductCard> */
    private function listed(): array
    {
        return app(CatalogReader::class)->listing(ListingQuery::fromRequestArray(['per_page' => '24']))->items;
    }

    /** @param list<ProductCard> $cards @return array<string, list<string>> product slug => labels (top first, then bottom) */
    private function labels(array $cards): array
    {
        $out = [];

        foreach ($cards as $card) {
            $out[$card->slug] = array_merge(array_map(static fn (Badge $b): string => $b->label, $card->badgesAt('top')), array_map(static fn (Badge $b): string => $b->label, $card->badgesAt('bottom')));
        }

        return $out;
    }

    // ================================================================ OFF = today's card

    /** ★ OFF: the card's HTML is byte-identical to the HTML taken from the view before the change (five shapes of card) */
    public function test_with_no_badge_the_card_html_is_exactly_todays(): void
    {
        app()->setLocale('en'); // the fixture was taken in the test locale
        $before = json_decode((string) file_get_contents(base_path('tests/Fixtures/storefront/card_html_before_badges.json')), true);

        foreach ($this->fixtureCards() as $name => $card) {
            $this->assertSame($before[$name], $this->renderCard($card, $name === 'full'), "card {$name}");
        }
    }

    /** ★ OFF after publishing a set with everything disabled: no key at all, the same HTML, the listing carries `badges: []` */
    public function test_publishing_everything_disabled_writes_nothing_and_changes_nothing(): void
    {
        app()->setLocale('en'); // the fixture was taken in the test locale
        $this->makeProduct(1, ['price' => '100.00', 'sale' => '80.00', 'stock' => 2]);
        $this->allOn();
        $this->assertNotNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::BADGES_KEY));

        $this->publish();

        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::BADGES_KEY), 'nothing is on: the key does not exist');
        $this->assertSame([], app(StorefrontAppearance::class)->badgeSet());
        $this->assertSame([], $this->listed()[0]->badges);

        $before = json_decode((string) file_get_contents(base_path('tests/Fixtures/storefront/card_html_before_badges.json')), true);

        foreach ($this->fixtureCards() as $name => $card) {
            $this->assertSame($before[$name], $this->renderCard($card, $name === 'full'), "card {$name}");
        }
    }

    // ================================================================ «Промо»

    /** ★ the word: the default word of the store language, or the merchant's own text; only on a discounted price block */
    public function test_the_sale_word_shows_on_a_discounted_product_only(): void
    {
        $this->makeProduct(1, ['price' => '100.00', 'sale' => '80.00', 'stock' => 9]);
        $this->makeProduct(2, ['price' => '100.00', 'stock' => 9]);

        $this->publish([BadgeSet::SALE => ['enabled' => true]]);
        $this->assertSame(['product-2' => [], 'product-1' => ['Промо']], $this->labels($this->listed()), 'the default word, in the language of the shop');

        $this->publish([BadgeSet::SALE => ['enabled' => true, 'text' => 'Sale']]);
        $this->assertSame(['product-2' => [], 'product-1' => ['Sale']], $this->labels($this->listed()));
    }

    /** ★ the percent comes from the price block the card prints: 100.00 → 80.00 = −20%, 99.99 → 66.66 = −33%; below 1% nothing in percent mode */
    public function test_the_sale_percent_is_worked_out_from_the_printed_block(): void
    {
        $this->makeProduct(1, ['price' => '100.00', 'sale' => '80.00', 'stock' => 9]);
        $this->makeProduct(2, ['price' => '99.99', 'sale' => '66.66', 'stock' => 9]);
        $this->makeProduct(3, ['price' => '100.00', 'sale' => '99.80', 'stock' => 9]);
        $this->makeProduct(4, ['price' => '100.00', 'stock' => 9]);

        $this->publish([BadgeSet::SALE => ['enabled' => true, 'mode' => 'percent', 'text' => 'ignored in percent mode']]);
        $labels = $this->labels($this->listed());

        $this->assertSame(['−20%'], $labels['product-1']);
        $this->assertSame(['−33%'], $labels['product-2']);
        $this->assertSame([], $labels['product-3'], '0.2% rounds to 0: not shown');
        $this->assertSame([], $labels['product-4']);

        $this->publish([BadgeSet::SALE => ['enabled' => true, 'mode' => 'word']]);
        $this->assertSame(['Промо'], $this->labels($this->listed())['product-3'], 'the word mode shows on any discount');
    }

    public function test_the_percent_is_whole_number_maths_half_up(): void
    {
        $this->assertSame(20, BadgeResolver::percent(new PriceBlock(8000, 8000, 'EUR', 10000)));
        $this->assertSame(33, BadgeResolver::percent(new PriceBlock(6666, 6666, 'EUR', 9999)));
        $this->assertSame(13, BadgeResolver::percent(new PriceBlock(8750, 8750, 'EUR', 10000)), '12.5 rounds up');
        $this->assertSame(1, BadgeResolver::percent(new PriceBlock(9950, 9950, 'EUR', 10000)), '0.5 rounds up to 1');
        $this->assertSame(0, BadgeResolver::percent(new PriceBlock(9951, 9951, 'EUR', 10000)));
        $this->assertSame(0, BadgeResolver::percent(new PriceBlock(8000, 8000, 'EUR', null)));
        $this->assertSame(0, BadgeResolver::percent(null));
    }

    /** ★ no own dates: a price list with a date range switches the badge on and off by itself */
    public function test_a_dated_price_list_switches_the_sale_badge_by_itself(): void
    {
        $this->makeProduct(1, ['price' => '100.00', 'stock' => 9]);
        $this->publish([BadgeSet::SALE => ['enabled' => true, 'mode' => 'percent']]);
        $shop = static fn (DateTimeImmutable $at): string => $at->setTimezone(app(StoreTimezone::class)->zone())->format('Y-m-d H:i');
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $list = app(PriceListWriter::class)->create(['name' => 'Soon', 'percentage' => '30', 'is_active' => true, 'replaces_manual_sale' => true, 'confirm_all_products' => true, 'scopes' => [], 'valid_from' => $shop($now->modify('+1 day'))]);
        $this->assertSame([], $this->labels($this->listed())['product-1'], 'before valid_from');

        DB::table('pricing_price_lists')->where('id', $list->id())->update(['valid_from' => $now->modify('-1 hour')->format('Y-m-d H:i:s')]);
        $this->assertSame(['−30%'], $this->labels($this->listed())['product-1'], 'inside the range');

        DB::table('pricing_price_lists')->where('id', $list->id())->update(['valid_until' => $now->modify('-1 minute')->format('Y-m-d H:i:s')]);
        $this->assertSame([], $this->labels($this->listed())['product-1'], 'after valid_until');
    }

    // ================================================================ «Нови»

    /** ★ the day boundary: with days = 14 a product created 14 calendar days ago is new, 15 is not */
    public function test_new_counts_whole_calendar_days(): void
    {
        $createdDay = '2026-03-01';

        $row = BadgeSet::defaultRow(BadgeSet::NEW);
        $row['enabled'] = true;
        $row['days'] = 14;
        $set = [BadgeSet::NEW => $row];

        $this->assertSame(['Нови'], array_map(static fn (Badge $b): string => $b->label, BadgeResolver::resolve($set, null, 0, $createdDay, '2026-03-15')), 'day 14: new');
        $this->assertSame([], BadgeResolver::resolve($set, null, 0, $createdDay, '2026-03-16'), 'day 15: not new');
        $this->assertSame([], BadgeResolver::resolve($set, null, 0, null, '2026-03-15'), 'no date: never');
        $this->assertSame([], BadgeResolver::resolve([], null, 0, $createdDay, '2026-03-15'), 'OFF: never');
    }

    /** ★ the same through the reader, in the STORE time zone: a product created in the last hour of a UTC day is the next day for a shop ahead of UTC */
    public function test_new_uses_the_store_time_zone_day(): void
    {
        $this->makeProduct(1, ['price' => '10.00', 'stock' => 9]);
        $this->makeProduct(2, ['price' => '10.00', 'stock' => 9]);
        app(SiteSettingsRepository::class)->set(StoreTimezone::KEY, 'Pacific/Kiritimati');
        $zone = new \DateTimeZone('Pacific/Kiritimati');
        $localDay = (new DateTimeImmutable('now', $zone))->setTime(0, 30);
        // product 1: 00:30 store time, 14 store days ago (the UTC day of that instant is 15 days ago); product 2: one more day back
        DB::table('catalog_products')->where('id', 1)->update(['created_at' => $localDay->modify('-14 days')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
        DB::table('catalog_products')->where('id', 2)->update(['created_at' => $localDay->modify('-15 days')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
        $this->publish([BadgeSet::NEW => ['enabled' => true, 'days' => 14]]);

        $labels = $this->labels($this->listed());

        $this->assertSame(['Нови'], $labels['product-1']);
        $this->assertSame([], $labels['product-2']);
    }

    // ================================================================ «Последни бройки»

    /** ★ the SUM of the shown variations' stock: 1..N shows, N+1 and 0 do not; sold out keeps its own label */
    public function test_low_stock_is_the_sum_of_the_shown_variations_between_one_and_n(): void
    {
        $this->makeAttribute(1, 'Size', ['S', 'M']);
        $this->makeProduct(1, ['price' => '10.00', 'stock' => 3]);
        $this->makeProduct(2, ['price' => '10.00', 'stock' => 4]);
        $this->makeProduct(3, ['price' => '10.00', 'stock' => 0]);
        $this->makeProduct(4, ['type' => 'variable', 'variations' => [['attrs' => [1 => 'S'], 'price' => '10.00', 'stock' => 1], ['attrs' => [1 => 'M'], 'price' => '10.00', 'stock' => 2]]]);
        $this->makeProduct(5, ['type' => 'variable', 'variations' => [['attrs' => [1 => 'S'], 'price' => '10.00', 'stock' => 2], ['attrs' => [1 => 'M'], 'price' => '10.00', 'stock' => 2]]]);

        $this->publish([BadgeSet::LOW_STOCK => ['enabled' => true, 'max' => 3]]);
        $labels = $this->labels($this->listed());

        $this->assertSame(['Последни бройки'], $labels['product-1'], 'stock 3 = N');
        $this->assertSame([], $labels['product-2'], 'stock 4 = N + 1');
        $this->assertSame([], $labels['product-3'], 'stock 0');
        $this->assertSame(['Последни бройки'], $labels['product-4'], '1 + 2 = 3');
        $this->assertSame([], $labels['product-5'], '2 + 2 = 4');

        $sold = collect($this->listed())->firstWhere('slug', 'product-3');
        $this->assertFalse($sold->inStock);
        $this->assertStringContainsString('Изчерпан', $this->renderCard($sold), 'the sold-out label stays');
        $this->assertStringNotContainsString('sf-cbadges', $this->renderCard($sold));
    }

    /** ★ N comes from the closed list 1..6 */
    public function test_a_stock_limit_outside_one_to_six_is_refused(): void
    {
        foreach ([0, 7, '3x', -1] as $bad) {
            $working = BadgeSet::defaults();
            $working['badges'][2]['max'] = $bad;
            [, $errors] = BadgeSet::validate($working);

            $this->assertArrayHasKey('low_stock.max', $errors, (string) $bad);
        }

        foreach ([1, 2, 3, 4, 5, 6] as $good) {
            $working = BadgeSet::defaults();
            $working['badges'][2]['max'] = $good;
            $this->assertSame([], BadgeSet::validate($working)[1]);
        }
    }

    // ================================================================ order and limit

    /** ★ at most two per position, by priority then kind order; the rest are dropped; the other position is separate */
    public function test_at_most_two_per_position_in_priority_order(): void
    {
        $block = new PriceBlock(8000, 8000, 'EUR', 10000);
        $row = static fn (string $kind, array $o): array => array_replace(BadgeSet::defaultRow($kind), ['enabled' => true], $o);

        $set = [
            BadgeSet::SALE => $row(BadgeSet::SALE, ['position' => 'top', 'priority' => 30]),
            BadgeSet::NEW => $row(BadgeSet::NEW, ['position' => 'top', 'priority' => 20]),
            BadgeSet::LOW_STOCK => $row(BadgeSet::LOW_STOCK, ['position' => 'top', 'priority' => 10]),
        ];
        $badges = BadgeResolver::resolve($set, $block, 2, '2026-03-15', '2026-03-15');

        $this->assertSame(['low_stock', 'new'], array_column(array_map(static fn (Badge $b): array => $b->toArray(), $badges), 'type'), 'priority 10 and 20 win; priority 30 is dropped, never wrapped');

        // equal priority: the kind order (built-ins in the fixed order) decides
        $set[BadgeSet::SALE]['priority'] = 10;
        $set[BadgeSet::LOW_STOCK]['priority'] = 10;
        $set[BadgeSet::NEW]['priority'] = 10;
        $this->assertSame(['sale', 'new'], array_map(static fn (Badge $b): string => $b->type, BadgeResolver::resolve($set, $block, 2, '2026-03-15', '2026-03-15')));

        // one at the top, two at the bottom: separate counts
        $set[BadgeSet::LOW_STOCK]['position'] = 'bottom';
        $set[BadgeSet::NEW]['position'] = 'bottom';
        $badges = BadgeResolver::resolve($set, $block, 2, '2026-03-15', '2026-03-15');
        $this->assertSame(['sale'], array_map(static fn (Badge $b): string => $b->type, array_filter($badges, static fn (Badge $b): bool => $b->placement === 'top')));
        $this->assertCount(2, array_filter($badges, static fn (Badge $b): bool => $b->placement === 'bottom'));
    }

    /** P7-1.2 ★ `image_bottom` holds ONE badge: of two enabled there, only the higher priority (then kind order) prints; top and bottom keep two */
    public function test_image_bottom_holds_one_badge(): void
    {
        $this->assertSame(['top' => 2, 'image_bottom' => 1, 'bottom' => 2], BadgeSet::PER_POSITION);

        $block = new PriceBlock(8000, 8000, 'EUR', 10000);
        $row = static fn (string $kind, array $o): array => array_replace(BadgeSet::defaultRow($kind), ['enabled' => true, 'position' => 'image_bottom'], $o);
        $set = [BadgeSet::SALE => $row(BadgeSet::SALE, ['priority' => 30]), BadgeSet::NEW => $row(BadgeSet::NEW, ['priority' => 20]), BadgeSet::LOW_STOCK => $row(BadgeSet::LOW_STOCK, ['position' => 'top'])];

        $badges = BadgeResolver::resolve($set, $block, 2, '2026-03-15', '2026-03-15');

        $this->assertSame(['image_bottom' => ['new'], 'top' => ['low_stock']], array_map(static fn (array $l): array => array_column(array_map(static fn (Badge $b): array => $b->toArray(), $l), 'type'), [
            'image_bottom' => array_values(array_filter($badges, static fn (Badge $b): bool => $b->placement === 'image_bottom')),
            'top' => array_values(array_filter($badges, static fn (Badge $b): bool => $b->placement === 'top')),
        ]));
    }

    /** P7-1.2: 70 % is for black / orange / grey only; the same 70 with red / blue / green is refused (and a stored copy with it renders nothing) */
    public function test_opacity_70_is_for_three_pairs_only(): void
    {
        foreach (BadgeSet::COLOURS as $colour) {
            $errors = BadgeSet::validate(['badges' => [['kind' => 'sale', 'colour' => $colour, 'opacity' => 70]]])[1];

            $this->assertSame(in_array($colour, ['black', 'orange', 'grey'], true) ? [] : ['sale.opacity'], array_keys($errors), $colour);
        }

        $this->assertSame(BadgeSet::validate(['badges' => [['kind' => 'sale', 'colour' => 'red', 'opacity' => 70]]])[1]['sale.opacity'], __('design.badges.errors.opacity_70'));
        $this->assertSame([], BadgeSet::fromJson('{"badges":[{"kind":"sale","enabled":true,"text":"","position":"top","colour":"green","opacity":70,"shape":"square","priority":1,"mode":"word"}]}'), 'a stored red/blue/green at 70 renders nothing');
        $this->assertArrayHasKey('sale', BadgeSet::fromJson('{"badges":[{"kind":"sale","enabled":true,"text":"","position":"top","colour":"black","opacity":70,"shape":"square","priority":1,"mode":"word"}]}'));
    }

    // ================================================================ markup

    /** ★ the footer prints for a bottom badge even when the card has no price line and is in stock; the TOP row is inside the picture frame */
    public function test_the_markup_of_the_two_rows(): void
    {
        $top = new Badge('sale', 'Промо', 'top', 'red', 10, 'pill');
        $bottom = new Badge('low_stock', 'Последни бройки', 'bottom', 'orange', 30, 'square');
        $image = $this->fixtureCards()['full']->image;

        $card = new ProductCard('9', 's', '/product/s', 'S', null, $image, null, [$top, $bottom], true);
        $html = $this->renderCard($card);

        $this->assertStringContainsString('<ul class="sf-cbadges sf-cbadges--top"><li class="sf-cbadge sf-cbadge--red sf-cbadge--pill sf-cbadge--o100">Промо</li></ul></div>', $html, 'inside .sf-card-image, right before its closing tag');
        $this->assertStringContainsString('<div class="sf-card-footer">', $html, 'the footer prints for the bottom badge');
        $this->assertStringContainsString('<ul class="sf-cbadges sf-cbadges--bottom"><li class="sf-cbadge sf-cbadge--orange sf-cbadge--square sf-cbadge--o100">Последни бройки</li></ul>', $html);
        $this->assertLessThan(strpos($html, 'sf-cbadges--bottom'), strpos($html, '</a>'), 'the bottom row is under the link, in the footer');

        // a top badge on a card with no picture: the placeholder is no longer aria-hidden (it holds a badge)
        $noImage = $this->renderCard(new ProductCard('9', 's', '/product/s', 'S', null, null, null, [$top], false));
        $this->assertStringContainsString('<div class="sf-card-image sf-image-placeholder"><ul class="sf-cbadges sf-cbadges--top">', $noImage);

        // P7-1.2: the third position is INSIDE the picture frame, right after the top row; a placeholder holding only it is not aria-hidden
        $ib = new Badge('new', 'Нови', 'image_bottom', 'black', 20, 'square', 70);
        $both = $this->renderCard(new ProductCard('9', 's', '/product/s', 'S', null, $image, null, [$top, $ib], true));
        $this->assertStringContainsString('</ul><ul class="sf-cbadges sf-cbadges--image-bottom"><li class="sf-cbadge sf-cbadge--black sf-cbadge--square sf-cbadge--o70">Нови</li></ul></div>', $both);
        $this->assertLessThan(strpos($both, '</a>'), strpos($both, 'sf-cbadges--image-bottom'), 'inside the link, in the picture frame');
        $this->assertStringNotContainsString('sf-card-footer', $both);
        $this->assertStringContainsString('<div class="sf-card-image sf-image-placeholder"><ul class="sf-cbadges sf-cbadges--image-bottom">', $this->renderCard(new ProductCard('9', 's', '/product/s', 'S', null, null, null, [$ib], false)));

        // an empty position prints nothing: only the top badge, so no bottom row and no footer for a priced-less in-stock card
        $onlyTop = $this->renderCard(new ProductCard('9', 's', '/product/s', 'S', null, $image, null, [$top], true));
        $this->assertStringNotContainsString('sf-cbadges--bottom', $onlyTop);
        $this->assertStringNotContainsString('sf-card-footer', $onlyTop);
    }

    /** ★ the text is escaped, never markup; the card carries no style attribute, script or handler */
    public function test_the_text_is_escaped_and_the_card_is_safe_html(): void
    {
        $this->makeProduct(1, ['price' => '100.00', 'sale' => '80.00', 'stock' => 2]);
        $this->allOn([BadgeSet::SALE => ['text' => '<b>Hot</b> & "new"']]);

        $html = $this->renderCard($this->listed()[0]);

        $this->assertStringContainsString('&lt;b&gt;Hot&lt;/b&gt; &amp; &quot;new&quot;', $html);
        $this->assertStringNotContainsString('<b>Hot', $html);
        $this->assertNoActiveContent($html, 'a card with badges');
    }

    // ================================================================ validation

    /** ★ closed lists and plain text: every bad value is refused and nothing is rewritten */
    public function test_validation_is_closed(): void
    {
        $bad = [
            ['text', str_repeat('a', 25), 'sale.text'],
            ['text', "line\nbreak", 'sale.text'],
            ['text', ['x'], 'sale.text'],
            ['colour', 'pink', 'sale.colour'],
            ['colour', 'white', 'sale.colour'],
            ['opacity', 70, 'sale.opacity'],
            ['opacity', '95', 'sale.opacity'],
            ['opacity', 0, 'sale.opacity'],
            ['opacity', 'x', 'sale.opacity'],
            ['colour', '#ff0000', 'sale.colour'],
            ['shape', 'star', 'sale.shape'],
            ['position', 'middle', 'sale.position'],
            ['priority', 0, 'sale.priority'],
            ['priority', 100, 'sale.priority'],
            ['priority', 'x', 'sale.priority'],
            ['enabled', 'yes', 'sale.enabled'],
            ['mode', 'euro', 'sale.mode'],
        ];

        foreach ($bad as [$field, $value, $path]) {
            $working = BadgeSet::defaults();
            $working['badges'][0][$field] = $value;

            $this->assertArrayHasKey($path, BadgeSet::validate($working)[1], $field.'='.json_encode($value));
        }

        $this->assertArrayHasKey('new.days', BadgeSet::validate(['badges' => [['kind' => 'new', 'days' => 91]]])[1]);
        $this->assertArrayHasKey('new.days', BadgeSet::validate(['badges' => [['kind' => 'new', 'days' => 0]]])[1]);
        $this->assertSame([], BadgeSet::validate(['badges' => [['kind' => 'free_shipping']]])[1], 'P7-2: «Безплатна доставка» is a built-in kind');
        $this->assertArrayHasKey('badges', BadgeSet::validate(['badges' => [['kind' => 'something_else']]])[1], 'an unknown kind is refused');
        $this->assertArrayHasKey('sale', BadgeSet::validate(['badges' => [['kind' => 'sale'], ['kind' => 'sale']]])[1], 'a kind twice');
        $this->assertArrayHasKey('badges', BadgeSet::validate('x')[1]);

        $this->assertSame([], BadgeSet::validate(BadgeSet::defaults())[1]);
        $this->assertSame([], BadgeSet::validate(['badges' => [['kind' => 'sale', 'text' => str_repeat('я', 24), 'priority' => '99']]])[1], '24 Cyrillic letters and a numeric string are fine');
    }

    /** ★ the colours are the six contrast pairs; an old token of the first version is read as its closest pair, so a working copy saved before is not lost */
    public function test_the_six_pairs_and_the_legacy_tokens(): void
    {
        $this->assertSame(['red', 'orange', 'blue', 'green', 'black', 'grey'], BadgeSet::COLOURS);
        $this->assertSame(['red', 'black', 'orange', 'green'], array_column(BadgeSet::defaults()['badges'], 'colour'), 'the defaults (P7-2 adds «Безплатна доставка»: green, under the price)');

        foreach (['dark' => 'black', 'light' => 'grey', 'neutral' => 'grey', 'purple' => 'blue'] as $old => $new) {
            [$clean, $errors] = BadgeSet::validate(['badges' => [['kind' => 'sale', 'colour' => $old]]]);

            $this->assertSame([], $errors, $old);
            $this->assertSame($new, $clean['badges'][0]['colour'], $old);
        }

        $this->assertSame(['red', 'orange', 'blue', 'green', 'black', 'grey'], array_keys(BadgeSet::PAIRS));

        foreach ([100, 90, 80, '90', 90.0] as $opacity) {
            $this->assertSame([], BadgeSet::validate(['badges' => [['kind' => 'sale', 'opacity' => $opacity]]])[1], json_encode($opacity));
        }

        $this->assertSame(100, BadgeSet::validate(['badges' => [['kind' => 'sale']]])[0]['badges'][0]['opacity'], 'a row without an opacity is 100');
    }

    /** the opacity reaches the markup as a closed class word, and only the first two steps print a class that changes anything */
    public function test_the_opacity_is_a_class_of_the_card(): void
    {
        $this->makeProduct(1, ['price' => '100.00', 'sale' => '80.00', 'stock' => 2]);
        $this->publish([BadgeSet::SALE => ['enabled' => true, 'opacity' => 80, 'shape' => 'rounded', 'colour' => 'blue']]);

        $html = $this->renderCard($this->listed()[0]);

        $this->assertStringContainsString('class="sf-cbadge sf-cbadge--blue sf-cbadge--rounded sf-cbadge--o80"', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*\bsf-badge\b/', $html, 'the menu label\'s class is never used on a card');
    }

    /** ★ a stored set that does not validate renders NOTHING (no error): the compiled key is read strictly */
    public function test_a_tampered_stored_set_renders_nothing(): void
    {
        $this->makeProduct(1, ['price' => '100.00', 'sale' => '80.00', 'stock' => 2]);
        $settings = app(SiteSettingsRepository::class);

        foreach ([
            '{"badges":[{"kind":"sale","enabled":true,"text":"x","position":"top","colour":"#ff0000","shape":"pill","priority":1,"mode":"word"}]}',
            '{"badges":[{"kind":"sale","enabled":true,"text":"x","position":"top","colour":"red","opacity":55,"shape":"pill","priority":1,"mode":"word"}]}',
            '{"badges":[{"kind":"sale","enabled":true,"text":"<script>","position":"top","colour":"red","shape":"pill","priority":1,"mode":"word"},{"kind":"evil","enabled":true}]}',
            'not json',
            '[]',
            '{"badges":"x"}',
        ] as $json) {
            $settings->set(AppearanceSettings::BADGES_KEY, $json);
            app(StorefrontAppearance::class)->refresh();
            $this->app->forgetScopedInstances();

            $this->assertSame([], app(StorefrontAppearance::class)->badgeSet(), $json);
            $this->assertSame([], $this->listed()[0]->badges, $json);
        }
    }

    // ================================================================ statements

    /** @return list<string> */
    private function queries(callable $work): array
    {
        $this->app->forgetScopedInstances();
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });
        $work();

        return $sql;
    }

    private function catalogOf24(): void
    {
        $this->makeAttribute(1, 'Size', ['S', 'M']);
        $categories = [$this->makeCategory(1, 'Clothing', 'clothing')];

        for ($i = 1; $i <= 24; $i++) {
            $variable = $i % 3 === 0;
            $this->makeProduct($i, [
                'type' => $variable ? 'variable' : 'simple',
                'categories' => $categories,
                'timeline' => sprintf('2026-01-%02d 10:00:00', $i),
                'price' => $variable ? null : '10.00',
                'sale' => $i % 4 === 0 && ! $variable ? '8.00' : null,
                'stock' => $i % 5 === 0 ? 0 : 3,
                'variations' => $variable ? [['attrs' => [1 => 'S'], 'price' => '10.00', 'stock' => 1], ['attrs' => [1 => 'M'], 'price' => '12.00', 'stock' => 0]] : null,
            ]);
            $this->makeImage($i, $i);
        }
    }

    /** ★ ALL three built-ins ON cost no statement on the listing, the category listing and the home grid; 5 cards and 24 cards cost the same (OFF is the same number too) */
    public function test_the_built_ins_cost_no_extra_statement(): void
    {
        $this->catalogOf24();
        $list = ListingQuery::fromRequestArray(['per_page' => '24']);
        $category = ListingQuery::fromRequestArray(['category' => '1']);
        $reader = fn () => app(CatalogReader::class);

        // every storefront request has ALREADY read the shop time zone (ApplyStoreTimezone), so the measured work starts from that memo; a first call warms the appearance cache
        $zone = fn () => app(StoreTimezone::class)->current();
        $measure = function () use ($reader, $list, $category, $zone): array {
            $this->queries(fn () => $reader()->listing($list));

            return [
                'listing' => count($this->queries(function () use ($reader, $list, $zone): void {
                    $zone();
                    $reader()->listing($list);
                })),
                'category' => count($this->queries(function () use ($reader, $category, $zone): void {
                    $zone();
                    $reader()->listing($category);
                })),
                'home' => count($this->queries(function () use ($reader, $zone): void {
                    $zone();
                    $reader()->homeProducts('newest', null, 'newest', 12, '100vw');
                })),
            ];
        };

        $off = $measure();

        $this->allOn();
        $on = $measure();
        $badged = collect($this->listed())->filter(fn (ProductCard $c): bool => $c->badges !== [])->count();

        $this->assertGreaterThan(0, $badged, 'the badges do print on this fixture');
        $this->assertSame($off, $on, 'OFF and ALL ON cost the same number of statements');

        DB::table('catalog_products')->where('id', '<=', 19)->update(['catalog_visibility' => 'hidden']);
        $this->assertSame($on['listing'], count($this->queries(function () use ($reader, $list, $zone): void {
            $zone();
            $reader()->listing($list);
        })), '5 cards cost what 24 cards cost');

        fwrite(STDERR, "\n[query-count] P7-1 badges: listing {$on['listing']}, category listing {$on['category']}, home grid {$on['home']} (OFF: {$off['listing']} / {$off['category']} / {$off['home']}); 5 cards = 24 cards\n");
    }

    /** the same through the real storefront: a category page and the home page cost the same ON as OFF */
    public function test_a_real_request_costs_the_same_with_the_badges_on(): void
    {
        $this->catalogOf24();

        $count = function (string $url): int {
            $this->get($url)->assertOk(); // warms the caches (the appearance entry, the menus)
            $this->app->forgetScopedInstances();
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            $this->get($url)->assertOk();

            return $n;
        };

        $off = [$count('/product-category/clothing'), $count('/')];
        $this->allOn();
        $on = [$count('/product-category/clothing'), $count('/')];

        $this->assertSame($off, $on);
    }
    // ================================================================ P7-2: «Безплатна доставка»

    /** the store's shipping: the country, its broad zone and one flat method with a free-shipping threshold (the writers fire the hooks that refresh the cached threshold) */
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

    private function freeShippingFixture(): void
    {
        $this->makeAttribute(1, 'Size', ['S', 'M']);
        $this->makeProduct(1, ['price' => '10.00', 'stock' => 9]);
        $this->makeProduct(2, ['price' => '15.00', 'stock' => 9]);
        $this->makeProduct(3, ['price' => '20.00', 'stock' => 9]);
        $this->makeProduct(4, ['type' => 'variable', 'variations' => [['attrs' => [1 => 'S'], 'price' => '10.00', 'stock' => 1], ['attrs' => [1 => 'M'], 'price' => '30.00', 'stock' => 1]]]);
    }

    /** ★ shown when the card's LOWEST price is at or above the threshold, so the promise is true for every variation */
    public function test_free_shipping_shows_at_and_above_the_threshold_only(): void
    {
        $this->freeShippingFixture();
        $this->shipping(1500);
        $this->publish([BadgeSet::FREE_SHIPPING => ['enabled' => true]]);

        $labels = $this->labels($this->listed());

        $this->assertSame([], $labels['product-1'], '10.00 is below 15.00');
        $this->assertSame(['Безплатна доставка'], $labels['product-2'], 'exactly at the threshold');
        $this->assertSame(['Безплатна доставка'], $labels['product-3']);
        $this->assertSame([], $labels['product-4'], 'a variable product by its LOWEST price (10.00), not its highest (30.00)');

        $card = collect($this->listed())->firstWhere('slug', 'product-2');
        $this->assertSame(['bottom', 'green', 40], [$card->badges[0]->placement, $card->badges[0]->style, $card->badges[0]->priority], 'under the price, green, priority 40');

        $this->publish([BadgeSet::FREE_SHIPPING => ['enabled' => true, 'text' => 'Free ship']]);
        $this->assertSame(['Free ship'], $this->labels($this->listed())['product-3'], 'the text is editable');
    }

    /** ★ not shown when shipping is free for everyone, when there is no threshold, or when the currencies differ */
    public function test_free_shipping_is_not_shown_without_a_meaningful_threshold(): void
    {
        $this->freeShippingFixture();
        $this->publish([BadgeSet::FREE_SHIPPING => ['enabled' => true]]);

        $none = fn (): bool => array_filter($this->labels($this->listed())) === [];

        $this->assertTrue($none(), 'no shipping configured: no threshold');

        $this->shipping(1500, freeMethod: true);
        $this->assertTrue($none(), 'an ACTIVE free method: shipping is free for everyone');

        Cache::forever(FreeShippingThreshold::KEY, ['minor' => 1000, 'currency' => 'USD']);
        $this->assertTrue($none(), 'a threshold in another currency than the card price');

        Cache::forever(FreeShippingThreshold::KEY, ['minor' => 1000, 'currency' => 'EUR']);
        $this->assertFalse($none(), 'the same cached value in the card currency does show (the check above was the currency)');
    }

    /** ★ OFF never reads the cache; ON reads it (one hit, warm: no statement) */
    public function test_free_shipping_off_never_reads_the_cache(): void
    {
        $this->freeShippingFixture();
        $this->shipping(1500);

        $reads = [];
        Event::listen([CacheHit::class, CacheMissed::class], function ($event) use (&$reads): void {
            $event->key === FreeShippingThreshold::KEY && $reads[] = $event::class;
        });

        $this->publish([BadgeSet::SALE => ['enabled' => true], BadgeSet::NEW => ['enabled' => true]]);
        $this->listed();
        $this->assertSame([], $reads, 'a page with the free-shipping badge OFF does not touch the threshold');

        $this->publish([BadgeSet::FREE_SHIPPING => ['enabled' => true]]);
        $this->listed();
        $this->assertSame([CacheHit::class], $reads, 'ON: one read of the cached value');

        $this->assertSame($this->statementsOfSet([BadgeSet::SALE => ['enabled' => true]]), $this->statementsOfSet([BadgeSet::SALE => ['enabled' => true], BadgeSet::FREE_SHIPPING => ['enabled' => true]]), 'a WARM threshold costs no statement');
    }

    /** @param array<string, array<string, mixed>> $over @return int the statements of one listing with this set published (the appearance cache warmed first) */
    private function statementsOfSet(array $over): int
    {
        $this->publish($over);
        $this->queries(fn () => $this->listed());

        return count($this->queries(function (): void {
            app(StoreTimezone::class)->current();
            $this->listed();
        }));
    }

    // ================================================================ P7-2: custom badges

    /** @return array<string, mixed> */
    private function customRow(string $id, string $text, array $conditions, array $o = []): array
    {
        return array_replace(['kind' => 'custom', 'id' => $id, 'enabled' => true, 'text' => $text, 'position' => 'top', 'colour' => 'blue', 'opacity' => 100, 'shape' => 'square', 'priority' => 50, 'conditions' => $conditions], $o);
    }

    /** @param list<array<string, mixed>> $customRows */
    private function publishCustom(array $customRows, array $over = []): void
    {
        $working = BadgeSet::defaults();

        foreach ($working['badges'] as $i => $row) {
            $working['badges'][$i] = array_replace($row, $over[$row['kind']] ?? []);
        }

        array_push($working['badges'], ...$customRows);
        [$clean, $errors] = BadgeSet::validate($working);
        $this->assertSame([], $errors, json_encode($errors, JSON_UNESCAPED_UNICODE));
        BadgeStore::publish($clean);
        $this->app->forgetScopedInstances();
    }

    private function customFixture(): void
    {
        $now = '2026-03-01 10:00:00';
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeCategory(2, 'Dresses', 'dresses', 1);
        $this->makeCategory(3, 'Shoes', 'shoes');
        $this->makeBrand(1, 'Acme', 'acme');
        $this->makeBrand(2, 'Zed', 'zed');
        $this->makeTag(1, 'Eco', 'eco');
        DB::table('catalog_seasons')->insert(['id' => 1, 'name' => 'Summer', 'slug' => 'summer', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('catalog_collections')->insert(['id' => 1, 'name' => 'Beach', 'slug' => 'beach', 'created_at' => $now, 'updated_at' => $now]);

        // 1: dress (child of Clothing), Acme, tag Eco, Summer, Beach, on sale · 2: shoes, Zed · 3: clothing, no brand, plain
        $this->makeProduct(1, ['categories' => [2], 'brand' => 1, 'tags' => [1], 'price' => '100.00', 'sale' => '80.00', 'stock' => 9]);
        $this->makeProduct(2, ['categories' => [3], 'brand' => 2, 'price' => '50.00', 'stock' => 9]);
        $this->makeProduct(3, ['categories' => [1], 'price' => '50.00', 'stock' => 9]);
        DB::table('catalog_products')->where('id', 1)->update(['season_id' => 1]);
        DB::table('catalog_product_collections')->insert(['product_id' => 1, 'collection_id' => 1]);
    }

    /** ★ a custom badge shows on the products that meet its condition, one badge per condition kind */
    public function test_a_custom_badge_follows_its_condition(): void
    {
        $this->customFixture();
        app(SiteSettingsRepository::class)->set(CatalogSubjectKind::COLLECTIONS_SWITCH, '1');

        $cases = [
            'categories' => [['categories' => [1]], ['product-1', 'product-3']], // «Clothing» and its descendant «Dresses»
            'categories (leaf)' => [['categories' => [3]], ['product-2']],
            'tags' => [['tags' => [1]], ['product-1']],
            'brands' => [['brands' => [2]], ['product-2']],
            'seasons' => [['seasons' => [1]], ['product-1']],
            'collections' => [['collections' => [1]], ['product-1']],
            'on_sale' => [['on_sale' => true], ['product-1']],
            'not on sale' => [['on_sale' => false], ['product-2', 'product-3']],
            'two conditions (AND)' => [['categories' => [1], 'brands' => [1]], ['product-1']],
        ];

        foreach ($cases as $name => [$conditions, $expected]) {
            $this->publishCustom([$this->customRow('aaaaaa0001', 'Special', $conditions)]);
            $shown = array_keys(array_filter($this->labels($this->listed()), fn (array $l): bool => $l === ['Special']));
            sort($shown);

            $this->assertSame($expected, $shown, $name);
        }
    }

    /** ★ while the collections are OFF a collections condition is false: the badge is hidden (the switch rides inside the statement) */
    public function test_collections_off_hides_a_collections_badge(): void
    {
        $this->customFixture();
        $this->publishCustom([$this->customRow('aaaaaa0001', 'Special', ['collections' => [1]])]);

        $this->assertSame([], array_filter($this->labels($this->listed())), 'switch OFF');

        app(SiteSettingsRepository::class)->set(CatalogSubjectKind::COLLECTIONS_SWITCH, '1');
        $this->assertSame(['Special'], $this->labels($this->listed())['product-1'], 'switch ON');
    }

    /** ★ the position caps and the priority order apply to ALL kinds together; on a tie the built-ins come first, then the custom ones in their stored order */
    public function test_all_kinds_share_the_caps_and_the_order(): void
    {
        $this->customFixture();
        $this->makeProduct(9, ['categories' => [1], 'price' => '100.00', 'sale' => '80.00', 'stock' => 9, 'timeline' => '2026-03-02 10:00:00']);
        $c = fn (string $id, string $text, int $priority): array => $this->customRow($id, $text, ['on_sale' => true], ['priority' => $priority]);

        // top: «Промо» (10) + «Нови»? (20, off) + custom A (5) + custom B (10): A (5) and then the tie at 10 → the built-in «Промо» before B
        $this->publishCustom([$c('aaaaaa0001', 'A', 5), $c('aaaaaa0002', 'B', 10), $c('aaaaaa0003', 'C', 10)], [BadgeSet::SALE => ['enabled' => true, 'priority' => 10]]);

        $this->assertSame(['A', 'Промо'], $this->labels($this->listed())['product-9'], 'two at the top: priority 5, then the tie at 10 goes to the built-in; B and C are dropped');

        $this->publishCustom([$c('aaaaaa0001', 'A', 10), $c('aaaaaa0002', 'B', 10), $c('aaaaaa0003', 'C', 10)]);
        $this->assertSame(['A', 'B'], $this->labels($this->listed())['product-9'], 'equal priority: the stored order');

        // one over the picture at the bottom: the cap is 1
        $img = fn (string $id, string $text, int $priority): array => $this->customRow($id, $text, ['on_sale' => true], ['priority' => $priority, 'position' => 'image_bottom']);
        $this->publishCustom([$img('aaaaaa0001', 'First', 20), $img('aaaaaa0002', 'Second', 10)]);
        $card = collect($this->listed())->firstWhere('slug', 'product-9');
        $this->assertSame(['Second'], array_map(static fn (Badge $b): string => $b->label, $card->badgesAt('image_bottom')), 'the cap of the third position is 1: the higher priority wins');
    }

    /** ★ the rules of the custom rows: a condition and a text are required, at most 12, the `attributes` rule is not offered, the id is stable and never repeated */
    public function test_the_custom_rows_are_validated(): void
    {
        $this->customFixture();
        $set = fn (array ...$rows): array => ['badges' => $rows];
        $errors = fn (array $row): array => BadgeSet::validate($set($row))[1];

        $this->assertSame([], $errors($this->customRow('aaaaaa0001', 'Ok', ['categories' => [1]])));
        $this->assertArrayHasKey('custom.0.conditions', $errors($this->customRow('aaaaaa0001', 'No condition', [])), 'no condition: it would be on every card');
        $this->assertArrayHasKey('custom.0.text', $errors($this->customRow('aaaaaa0001', '', ['categories' => [1]])), 'the text is required');
        $this->assertArrayHasKey('custom.0.text', $errors($this->customRow('aaaaaa0001', str_repeat('x', 25), ['categories' => [1]])));
        $this->assertArrayHasKey('custom.0.conditions', $errors($this->customRow('aaaaaa0001', 'Attr', ['attributes' => [['attribute' => 1, 'values' => [1]]]])), 'attribute rules are not offered');
        $this->assertArrayHasKey('custom.0.conditions', $errors($this->customRow('aaaaaa0001', 'Unknown id', ['categories' => [999]])), 'the form checks that the category exists');
        $this->assertArrayHasKey('custom.0.id', $errors($this->customRow('', 'No id', ['categories' => [1]])));
        $this->assertArrayHasKey('custom.0.id', $errors($this->customRow('BAD ID!', 'Bad id', ['categories' => [1]])));
        $this->assertArrayHasKey('custom.1.id', BadgeSet::validate($set($this->customRow('aaaaaa0001', 'A', ['categories' => [1]]), $this->customRow('aaaaaa0001', 'B', ['categories' => [1]])))[1], 'an id twice');

        $rows = [];

        for ($i = 0; $i < 13; $i++) {
            $rows[] = $this->customRow(sprintf('aaaaaa%04d', $i), "B{$i}", ['categories' => [1]]);
        }

        $this->assertSame([], BadgeSet::validate($set(...array_slice($rows, 0, 12)))[1], '12 are fine');
        $this->assertArrayHasKey('custom', BadgeSet::validate($set(...$rows))[1], 'the 13th is refused');
    }

    /** ★ the id survives a reorder, a draft and a publish; a published copy that does not validate renders nothing */
    public function test_the_id_survives_reorder_and_a_tampered_copy_renders_nothing(): void
    {
        $this->customFixture();
        $a = $this->customRow('aaaaaa0001', 'A', ['categories' => [1]], ['priority' => 30]);
        $b = $this->customRow('bbbbbb0002', 'B', ['categories' => [3]], ['priority' => 20]);

        foreach ([[$a, $b], [$b, $a]] as $order) {
            [$clean] = BadgeSet::validate(['badges' => $order]);
            $ids = array_column(array_slice($clean['badges'], 4), 'id');
            $this->assertSame(array_column($order, 'id'), $ids, 'a stored order is kept; the id belongs to its row');
            $this->assertEquals(['A' => 'aaaaaa0001', 'B' => 'bbbbbb0002'], array_column(array_slice($clean['badges'], 4), 'id', 'text'));
        }

        $this->publishCustom([$a, $b]);
        $this->assertSame(['aaaaaa0001', 'bbbbbb0002'], array_column(BadgeSet::fromJson(app(SiteSettingsRepository::class)->get(AppearanceSettings::BADGES_KEY))['custom'], 'id'));
        $this->makeProduct(7, ['categories' => [1, 3], 'price' => '50.00', 'stock' => 9]);
        $this->assertSame(['B', 'A'], $this->labels($this->listed())['product-7'], 'priority 20 (B) before 30 (A) on the product that matches both');

        $json = '{"badges":[{"kind":"custom","id":"aaaaaa0001","enabled":true,"text":"x","position":"top","colour":"blue","opacity":100,"shape":"square","priority":1,"conditions":{"attributes":[{"attribute":1,"values":[1]}]}}]}';
        $this->assertSame([], BadgeSet::fromJson($json), 'a condition kind the badges do not offer: nothing renders');
        $this->assertSame([], BadgeSet::fromJson(str_replace('"conditions":{"attributes":[{"attribute":1,"values":[1]}]}', '"conditions":{}', $json)), 'no condition: nothing renders');
    }

    /** ★ the text of a custom badge is escaped by the view, never markup */
    public function test_a_custom_text_is_escaped(): void
    {
        $this->customFixture();
        $this->publishCustom([$this->customRow('aaaaaa0001', '<b>Hot</b> & "new"', ['on_sale' => true])]);

        $html = $this->renderCard(collect($this->listed())->firstWhere('slug', 'product-1'));

        $this->assertStringContainsString('&lt;b&gt;Hot&lt;/b&gt; &amp; &quot;new&quot;', $html);
        $this->assertStringNotContainsString('<b>Hot', $html);
        $this->assertNoActiveContent($html, 'a card with a custom badge');
    }

    /** the signed preview shows the WORKING copy, custom rows included (the browser run found a 500 here: the custom rows must be a list under `custom`) */
    public function test_the_preview_of_a_working_copy_carries_custom_rows(): void
    {
        $this->customFixture();
        [$working] = BadgeSet::validate(['badges' => [$this->customRow('aaaaaa0001', 'Preview', ['on_sale' => true])]]);

        app(StorefrontAppearance::class)->previewBadges($working);

        $this->assertSame(['Preview'], $this->labels($this->listed())['product-1']);
    }

    // ================================================================ P7-2: statements

    /**
     * ★ a listing page: NO custom badge enabled = 0 added statements; custom badges using ALL the condition kinds add at most 5 (categories 2: the pivot and the tree, tags 1, collections 1; brand, season and «on sale» ride on
     * data the page already has), and the number does not depend on the number of cards (3 and 12 cost the same)
     */
    public function test_custom_badges_cost_at_most_five_statements_whatever_the_number_of_cards(): void
    {
        $now = '2026-03-01 10:00:00';
        $this->catalogOf24();
        $this->makeBrand(1, 'Acme', 'acme');
        $this->makeTag(1, 'Eco', 'eco');
        DB::table('catalog_seasons')->insert(['id' => 1, 'name' => 'Summer', 'slug' => 'summer', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('catalog_collections')->insert(['id' => 1, 'name' => 'Beach', 'slug' => 'beach', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('catalog_product_tags')->insert(['product_id' => 1, 'tag_id' => 1]);

        $all = [['aaaaaa0001', ['categories' => [1]]], ['aaaaaa0002', ['tags' => [1]]], ['aaaaaa0003', ['brands' => [1]]], ['aaaaaa0004', ['seasons' => [1]]], ['aaaaaa0005', ['collections' => [1]]], ['aaaaaa0006', ['on_sale' => true]]];

        $off = $this->statementsOf([]);
        $this->assertSame($off, $this->statementsOf($all, enabled: false), 'custom badges that are all switched off cost nothing');

        $on24 = $this->statementsOf($all);
        $this->assertGreaterThan(0, count(array_filter($this->labels($this->listed()))), 'the custom badges do print on this fixture');
        $this->assertLessThanOrEqual(5, $on24 - $off, 'all five condition kinds together');
        $this->assertGreaterThan(0, $on24 - $off);

        DB::table('catalog_products')->where('id', '>', 3)->update(['catalog_visibility' => 'hidden']);
        $this->assertSame($on24, $this->statementsOf($all), '3 cards cost what 24 cards cost');
        $this->assertSame($off, $this->statementsOf([]), 'and OFF is the same for 3 cards');

        fwrite(STDERR, "\n[query-count] P7-2 custom badges: OFF {$off}, five kinds on {$on24} (+".($on24 - $off).'), 3 cards = 24 cards'."\n");
    }

    /** @param list<array{0: string, 1: array<string, mixed>}> $custom @return int what ONE listing of these custom rows costs (the appearance cache warm, the shop time zone read before) */
    private function statementsOf(array $custom, bool $enabled = true): int
    {
        $this->publishCustom(array_map(fn (array $c): array => $this->customRow($c[0], 'X', $c[1], ['enabled' => $enabled]), $custom));
        $this->queries(fn () => $this->listed());
        ProductContext::forgetShared();

        return count($this->queries(function (): void {
            app(StoreTimezone::class)->current();
            $this->listed();
        }));
    }
}
