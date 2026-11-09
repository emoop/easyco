<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
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
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Persistence\Eloquent\PromotionRedemptionModel;
use EasyCo\Promotions\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * P5, Part B: the public promo codes in the cart. The list is built ONLY by GET /api/cart?public_promotions=1; every
 * verdict is the validator's (the same one applying a code uses).
 */
class PublicPromotionsTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;

    private ?PriceList $regular = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost/');
        $this->withoutVite();
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    /** A cart of $quantity pieces of a 1.00 EUR product (so the subtotal is $quantity.00 EUR). */
    private function cartOfEuros(int $quantity): string
    {
        self::$counter++;
        $n = self::$counter;
        $product = Product::createSimple("P5B Product {$n}", "P5B-{$n}", "p5b-product-{$n}");
        app(ProductRepository::class)->save($product);
        $id = $product->variations()[0]->id();

        if ($this->regular === null) {
            $this->regular = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->regular);
        }

        app(PriceListItemRepository::class)->save(new PriceListItem(null, $this->regular->id(), PriceListItemTargetType::VARIATION, $id, Price::exclusiveOfTax(Money::fromDecimal('1.00', 'EUR'), 0)));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($id, 500));
        $this->postJson('/api/cart/lines', ['variation_id' => $id, 'quantity' => $quantity])->assertStatus(201);

        return $id;
    }

    private function promo(string $code, ?string $description = 'A short description', array $overrides = []): Promotion
    {
        $promotion = Promotion::create(
            code: $code,
            discountType: PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 1000,
            minimumSpend: $overrides['min'] ?? null,
            newCustomersOnly: $overrides['newOnly'] ?? false,
            usageLimitTotal: $overrides['limit'] ?? null,
            validFrom: $overrides['from'] ?? null,
            validUntil: $overrides['until'] ?? null,
            isPublic: $description !== null,
            publicDescription: $description,
        );

        if (($overrides['inactive'] ?? false) === true) {
            $promotion->deactivate();
        }

        app(PromotionRepository::class)->save($promotion);

        return $promotion;
    }

    /** @return array<string, mixed> the list's `items`, keyed by code */
    private function listed(): array
    {
        $items = $this->getJson('/api/cart?public_promotions=1')->assertOk()->json('public_promotions.items') ?? [];

        return array_column($items, null, 'code');
    }

    private function queryCount(callable $call): int
    {
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        $call();

        return $n;
    }

    // ---- what is listed ------------------------------------------------------------------------------------------

    /** ★ a promotion that is not flagged public is NEVER in the list, valid for this cart or not */
    public function test_a_non_public_code_is_never_listed(): void
    {
        $this->cartOfEuros(5);
        $this->promo('personal10', null);
        $this->promo('open10', 'For everyone');

        $this->assertSame(['open10'], array_keys($this->listed()));
        $this->assertStringNotContainsString('personal10', $this->getJson('/api/cart?public_promotions=1')->getContent());
    }

    /** ★ a code that is switched off, not yet started, expired or used up is not offered at all */
    public function test_inactive_not_yet_started_expired_and_used_up_public_codes_are_not_listed(): void
    {
        $this->cartOfEuros(5);
        $this->promo('off', 'x', ['inactive' => true]);
        $this->promo('later', 'x', ['from' => new DateTimeImmutable('+2 days')]);
        $this->promo('gone', 'x', ['until' => new DateTimeImmutable('-1 day'), 'from' => new DateTimeImmutable('-5 days')]);
        $used = $this->promo('used', 'x', ['limit' => 1]);
        $this->promo('fine', 'x');

        // the redemption's order is irrelevant to this test: the foreign key to `orders` is lifted for the one insert
        Schema::disableForeignKeyConstraints();
        PromotionRedemptionModel::query()->forceCreate([
            'promotion_id' => $used->id(), 'order_id' => '1', 'account_id' => null, 'redeemed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Schema::enableForeignKeyConstraints();

        $this->assertSame(['fine'], array_keys($this->listed()));
    }

    /** ★ the description is data: the API hands it over as plain text and the page script only ever sets textContent */
    public function test_the_description_is_plain_text_never_markup(): void
    {
        $this->cartOfEuros(5);
        $this->promo('xss', '<script>alert(1)</script>');

        $this->assertSame('<script>alert(1)</script>', $this->listed()['xss']['description']);

        $js = (string) file_get_contents(base_path('resources/js/storefront-cart.js'));
        $start = (int) strpos($js, 'function publicPromotionList');
        $end = (int) strpos($js, 'function promotionBox');
        $section = substr($js, $start, $end - $start);

        $this->assertStringNotContainsString('innerHTML', $section);
        $this->assertStringNotContainsString('insertAdjacentHTML', $section);
        $this->assertStringNotContainsString('.style', $section);

        $this->assertStringNotContainsString('xss', $this->get('/cart')->getContent());
    }

    public function test_the_states_applicable_applied_below_minimum_and_unavailable(): void
    {
        $this->cartOfEuros(79); // 79.00 €
        $this->promo('open', 'Always');
        $this->promo('big', 'From 100', ['min' => Money::fromDecimal('100.00', 'EUR')]);
        $this->promo('newonly', 'First order', ['newOnly' => true]);

        $items = $this->listed();
        $this->assertSame('applicable', $items['open']['state']);
        $this->assertSame('below_minimum', $items['big']['state']);
        $this->assertSame(['minor' => 2100, 'currency' => 'EUR'], $items['big']['remaining']);
        $this->assertSame('unavailable', $items['newonly']['state']);
        $this->assertNull($items['newonly']['remaining']);

        $this->putJson('/api/cart/promotion', ['code' => 'open'])->assertOk();
        $this->assertSame('applied', $this->listed()['open']['state']);
    }

    /** the exact gap of the brief: minimum 100.00 €, cart 79.99 € → 20.01 € */
    public function test_the_remaining_amount_is_exact_to_the_cent(): void
    {
        $this->cartOfEuros(1);
        $id = DB::table('pricing_price_list_items')->value('target_id');
        DB::table('pricing_price_list_items')->where('target_id', $id)->update(['price_amount_minor' => 7999]);
        $this->promo('big', 'From 100', ['min' => Money::fromDecimal('100.00', 'EUR')]);

        $this->assertSame(['minor' => 2001, 'currency' => 'EUR'], $this->listed()['big']['remaining']);
    }

    /** a code that is below the minimum AND blocked by something else is disabled, with no hint */
    public function test_below_minimum_with_another_obstacle_is_unavailable(): void
    {
        $this->cartOfEuros(5);
        $this->promo('both', 'x', ['min' => Money::fromDecimal('100.00', 'EUR'), 'newOnly' => true]);

        $this->assertSame('unavailable', $this->listed()['both']['state']);
        $this->assertNull($this->listed()['both']['remaining']);
    }

    public function test_order_is_applicable_then_smallest_remaining_then_the_rest_and_the_list_is_capped_at_eight(): void
    {
        $this->cartOfEuros(50);
        $this->promo('zz-open', 'x');
        $this->promo('aa-open', 'x');
        $this->promo('far', 'x', ['min' => Money::fromDecimal('200.00', 'EUR')]);
        $this->promo('near', 'x', ['min' => Money::fromDecimal('60.00', 'EUR')]);
        $this->promo('newonly', 'x', ['newOnly' => true]);

        $this->assertSame(['aa-open', 'zz-open', 'near', 'far', 'newonly'], array_column($this->getJson('/api/cart?public_promotions=1')->json('public_promotions.items'), 'code'));

        for ($i = 0; $i < 9; $i++) {
            $this->promo('extra'.$i, 'x');
        }

        $items = $this->getJson('/api/cart?public_promotions=1')->json('public_promotions.items');
        $this->assertCount(8, $items);
        $this->assertSame('applicable', $items[7]['state']);
    }

    public function test_no_public_codes_means_no_list_at_all(): void
    {
        $this->cartOfEuros(5);
        $this->promo('personal', null);

        $this->assertArrayNotHasKey('public_promotions', $this->getJson('/api/cart?public_promotions=1')->json());
    }

    // ---- where the list is and is not -----------------------------------------------------------------------------

    /** ★ only the cart read that asks for it carries the list: not the plain read (the header badge), not a write */
    public function test_only_the_asking_cart_read_carries_the_list(): void
    {
        $id = $this->cartOfEuros(5);
        $this->promo('open', 'x');

        $this->assertArrayHasKey('public_promotions', $this->getJson('/api/cart?public_promotions=1')->json());
        $this->assertArrayNotHasKey('public_promotions', $this->getJson('/api/cart')->json());
        $this->assertArrayNotHasKey('public_promotions', $this->putJson('/api/cart/promotion', ['code' => 'open'])->json());
        $this->assertArrayNotHasKey('public_promotions', $this->patchJson('/api/cart/lines/'.$id, ['quantity' => 6])->json());
        $this->assertArrayNotHasKey('public_promotions', $this->deleteJson('/api/cart/promotion')->json());
        $this->assertArrayNotHasKey('public_promotions', $this->postJson('/api/cart/lines', ['variation_id' => $id, 'quantity' => 1])->json());
    }

    /** ★ a write costs the same with public codes as without: the list is not built there */
    public function test_a_write_costs_no_extra_query_because_of_public_codes(): void
    {
        $id = $this->cartOfEuros(5);
        $this->promo('personal', null);
        $this->patchJson('/api/cart/lines/'.$id, ['quantity' => 5])->assertOk(); // warm-up: the first request of a process reads a few things once
        $without = $this->queryCount(fn () => $this->patchJson('/api/cart/lines/'.$id, ['quantity' => 6])->assertOk());

        for ($i = 0; $i < 4; $i++) {
            $this->promo('open'.$i, 'x', ['limit' => 100]);
        }

        $with = $this->queryCount(fn () => $this->patchJson('/api/cart/lines/'.$id, ['quantity' => 7])->assertOk());

        $this->assertSame($without, $with);
    }

    /** ★ the read's query count does not grow with the number of public codes (each with a usage limit) */
    public function test_the_list_costs_a_constant_number_of_queries(): void
    {
        $this->cartOfEuros(5);
        $this->promo('one', 'x', ['limit' => 100]);
        $this->getJson('/api/cart?public_promotions=1')->assertOk(); // warm-up: the store locale setting is read once per process
        $one = $this->queryCount(fn () => $this->getJson('/api/cart?public_promotions=1')->assertOk());

        for ($i = 0; $i < 7; $i++) {
            $this->promo('more'.$i, 'x', ['limit' => 100, 'newOnly' => true]);
        }

        $eight = $this->queryCount(fn () => $this->getJson('/api/cart?public_promotions=1')->assertOk());
        $plain = $this->queryCount(fn () => $this->getJson('/api/cart')->assertOk());

        fwrite(STDERR, "\n[query-count] cart read: plain {$plain}, with list of 1 code {$one}, with list of 8 codes {$eight}\n");
        $this->assertSame($one, $eight);
    }

    /** the list's texts follow the STORE's locale (the api group sets none of its own), not the PHP default */
    public function test_the_texts_follow_the_store_locale(): void
    {
        $this->cartOfEuros(5);
        $this->promo('open', 'x');

        $texts = $this->getJson('/api/cart?public_promotions=1')->json('public_promotions.texts');

        $this->assertSame(trans('storefront.public_promos.apply', [], app(\App\Settings\StoreLocale::class)->current()), $texts['apply']);
        $this->assertSame(array_keys(trans('storefront.public_promos', [], 'bg')), array_keys($texts));
    }

    /** applying from the list is the same call as typing the code: same totals */
    public function test_applying_from_the_list_equals_typing_the_code(): void
    {
        $this->cartOfEuros(50);
        $this->promo('open', 'x');
        $this->assertSame('applicable', $this->listed()['open']['state']);

        $typed = $this->putJson('/api/cart/promotion', ['code' => 'OPEN'])->assertOk()->json();
        $read = $this->getJson('/api/cart?public_promotions=1')->assertOk()->json();

        $this->assertSame($typed['total'], $read['total']);
        $this->assertSame(['minor' => 500, 'currency' => 'EUR'], $read['promotion']['discount_amount']);
        $this->assertSame('applied', $read['public_promotions']['items'][0]['state']);
    }

    // ---- the data rules ------------------------------------------------------------------------------------------

    public function test_a_public_promotion_needs_a_description_of_at_most_120_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->promo('nodesc', '   ');
    }

    public function test_the_description_cannot_be_longer_than_120_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->promo('long', str_repeat('я', 121));
    }

    /** ★ a row edited by hand (public, but an empty or over-long description) must not break any read: it comes back non-public, and the cart, applying a code and the list keep working */
    public function test_a_badly_written_public_row_is_read_back_as_non_public_and_breaks_nothing(): void
    {
        $this->cartOfEuros(5);
        $this->promo('good', 'Fine');
        $this->promo('empty', 'x');
        $this->promo('long', 'x');
        DB::table('promotions')->where('code', 'empty')->update(['public_description' => '']);
        DB::table('promotions')->where('code', 'long')->update(['public_description' => '   ']); // (an over-long text cannot even be stored: the column is 120 characters; a blank one can, and so can a NULL)
        DB::table('promotions')->where('code', 'good')->update(['public_description' => 'Fine']);

        $repository = app(PromotionRepository::class);
        $this->assertFalse($repository->findByCode('empty')->isPublic());
        $this->assertFalse($repository->findByCode('long')->isPublic());
        $this->assertCount(1, $repository->allPublicActive(), 'only the well-written public row is returned');
        $this->assertCount(3, $repository->all());

        $this->assertSame(['good'], array_keys($this->listed()));
        $this->putJson('/api/cart/promotion', ['code' => 'empty'])->assertOk();
        $this->getJson('/api/cart')->assertOk();
    }

    public function test_a_non_public_promotion_keeps_no_description(): void
    {
        $promotion = Promotion::create('quiet', PromotionDiscountType::PERCENTAGE, 1000, publicDescription: 'left over');

        $this->assertFalse($promotion->isPublic());
        $this->assertNull($promotion->publicDescription());
    }

    public function test_existing_promotions_are_non_public_by_default_and_the_flag_round_trips(): void
    {
        $plain = Promotion::create('plain', PromotionDiscountType::PERCENTAGE, 1000);
        app(PromotionRepository::class)->save($plain);
        $this->promo('shown', 'Hello');

        $this->assertFalse(app(PromotionRepository::class)->findByCode('plain')->isPublic());
        $shown = app(PromotionRepository::class)->findByCode('shown');
        $this->assertTrue($shown->isPublic());
        $this->assertSame('Hello', $shown->publicDescription());
    }

    public function test_the_admin_api_requires_a_description_for_a_public_code_and_keeps_a_personal_one_non_public(): void
    {
        $this->actingAsAdministrator();
        $base = ['code' => 'PUB', 'discount_type' => 'percentage', 'percentage_basis_points' => 1000];

        $this->postJson('/api/promotions', $base + ['is_public' => true])->assertStatus(422)->assertJsonValidationErrors('public_description');
        $this->postJson('/api/promotions', $base + ['is_public' => true, 'public_description' => str_repeat('a', 121)])->assertStatus(422)->assertJsonValidationErrors('public_description');
        $this->postJson('/api/promotions', $base + ['is_public' => true, 'public_description' => "line\nbreak"])->assertStatus(422)->assertJsonValidationErrors('public_description');

        $this->postJson('/api/promotions', $base + ['is_public' => true, 'public_description' => '10% off'])->assertStatus(201)->assertJson(['is_public' => true, 'public_description' => '10% off']);
        $this->postJson('/api/promotions', ['code' => 'PERSONAL'] + $base + ['public_description' => 'ignored'])->assertStatus(201)->assertJson(['is_public' => false, 'public_description' => null]);

        $this->assertContains(true, array_column($this->getJson('/api/promotions')->json(), 'is_public'));
    }
}
