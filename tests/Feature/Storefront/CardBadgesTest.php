<?php

namespace Tests\Feature\Storefront;

use App\Services\PriceListWriter;
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
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->assertStringNotContainsString('sf-badges', $this->renderCard($sold));
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

    // ================================================================ markup

    /** ★ the footer prints for a bottom badge even when the card has no price line and is in stock; the TOP row is inside the picture frame */
    public function test_the_markup_of_the_two_rows(): void
    {
        $top = new Badge('sale', 'Промо', 'top', 'red', 10, 'pill');
        $bottom = new Badge('low_stock', 'Последни бройки', 'bottom', 'orange', 30, 'square');
        $image = $this->fixtureCards()['full']->image;

        $card = new ProductCard('9', 's', '/product/s', 'S', null, $image, null, [$top, $bottom], true);
        $html = $this->renderCard($card);

        $this->assertStringContainsString('<ul class="sf-badges sf-badges--top"><li class="sf-badge sf-badge--red sf-badge--pill">Промо</li></ul></div>', $html, 'inside .sf-card-image, right before its closing tag');
        $this->assertStringContainsString('<div class="sf-card-footer">', $html, 'the footer prints for the bottom badge');
        $this->assertStringContainsString('<ul class="sf-badges sf-badges--bottom"><li class="sf-badge sf-badge--orange sf-badge--square">Последни бройки</li></ul>', $html);
        $this->assertLessThan(strpos($html, 'sf-badges--bottom'), strpos($html, '</a>'), 'the bottom row is under the link, in the footer');

        // a top badge on a card with no picture: the placeholder is no longer aria-hidden (it holds a badge)
        $noImage = $this->renderCard(new ProductCard('9', 's', '/product/s', 'S', null, null, null, [$top], false));
        $this->assertStringContainsString('<div class="sf-card-image sf-image-placeholder"><ul class="sf-badges sf-badges--top">', $noImage);

        // an empty position prints nothing: only the top badge, so no bottom row and no footer for a priced-less in-stock card
        $onlyTop = $this->renderCard(new ProductCard('9', 's', '/product/s', 'S', null, $image, null, [$top], true));
        $this->assertStringNotContainsString('sf-badges--bottom', $onlyTop);
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
        $this->assertArrayHasKey('badges', BadgeSet::validate(['badges' => [['kind' => 'free_shipping']]])[1], 'P7-2 kinds are not accepted yet');
        $this->assertArrayHasKey('sale', BadgeSet::validate(['badges' => [['kind' => 'sale'], ['kind' => 'sale']]])[1], 'a kind twice');
        $this->assertArrayHasKey('badges', BadgeSet::validate('x')[1]);

        $this->assertSame([], BadgeSet::validate(BadgeSet::defaults())[1]);
        $this->assertSame([], BadgeSet::validate(['badges' => [['kind' => 'sale', 'text' => str_repeat('я', 24), 'priority' => '99']]])[1], '24 Cyrillic letters and a numeric string are fine');
    }

    /** ★ a stored set that does not validate renders NOTHING (no error): the compiled key is read strictly */
    public function test_a_tampered_stored_set_renders_nothing(): void
    {
        $this->makeProduct(1, ['price' => '100.00', 'sale' => '80.00', 'stock' => 2]);
        $settings = app(SiteSettingsRepository::class);

        foreach ([
            '{"badges":[{"kind":"sale","enabled":true,"text":"x","position":"top","colour":"#ff0000","shape":"pill","priority":1,"mode":"word"}]}',
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
}
