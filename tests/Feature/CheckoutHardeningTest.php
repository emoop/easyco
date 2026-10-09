<?php

namespace Tests\Feature;

use App\Services\Exceptions\SaleLineOrderReconciliationException;
use App\Services\SaleLineSnapshotBuilder;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Extensibility\Hook;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Payment\Contracts\PaymentMethodAdapter;
use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentAttemptResult;
use EasyCo\Payment\PaymentContext;
use EasyCo\Payment\PaymentRefundAttemptResult;
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
use EasyCo\Promotions\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\ProvidesCheckoutShipping;
use Tests\TestCase;

/**
 * Shipping stage 4c (shipping-domain-design.md §9.1.5, §9.1.8, §9.1.9): checkout hardening before money.
 * Phase 2 contained (B2, O5, O6), controlled refusals instead of 500s (B4), the store's locale (B3),
 * and input security. Real MySQL, real HTTP, the cart built through the API like the storefront does.
 */
class CheckoutHardeningTest extends TestCase
{
    use RefreshDatabase;
    use ProvidesCheckoutShipping;

    private const SCRIPT = '<script>alert("x")</script>';

    private static int $counter = 0;

    private ?PriceList $priceList = null;

    private string $cartId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost/');
    }

    // --- fixtures -------------------------------------------------------------------------------------------------

    private function variation(string $price = '10.00', int $stock = 10, string $currency = 'EUR'): string
    {
        self::$counter++;
        $n = self::$counter;
        $product = Product::createSimple("Hardening Product {$n}", "HD-{$n}", "hardening-product-{$n}");
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        $this->priceList ??= tap(PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0), fn (PriceList $list) => app(PriceListRepository::class)->save($list));

        app(PriceListItemRepository::class)->save(new PriceListItem(
            null, $this->priceList->id(), PriceListItemTargetType::VARIATION, $variationId,
            Price::exclusiveOfTax(Money::fromDecimal($price, $currency), 0),
        ));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $stock));

        return $variationId;
    }

    private function fillCart(string $variationId, int $quantity = 1): void
    {
        $this->cartId = (string) $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => $quantity])->assertStatus(201)->json('cart_id');
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'cart_id' => $this->cartId,
            'email' => 'guest@example.com',
            'recipient_name' => 'Guest Buyer',
            'phone' => '+359888000000',
            'payment_method' => 'cash_on_delivery',
            'delivery_type' => 'street_address',
            'country' => 'BG',
            'city' => 'Sofia',
            'address_line_1' => 'Vitosha Blvd 1',
            ...$this->shippingPayload(),
        ], $override);
    }

    private function checkout(array $override = []): TestResponse
    {
        return $this->postJson('/api/checkout', $this->payload($override));
    }

    private function storeLocale(string $locale): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', $locale);
    }

    /** @return array<string, int> */
    private function rows(): array
    {
        return [
            'orders' => DB::table('orders')->count(),
            'payments' => DB::table('payments')->count(),
            'snapshots' => DB::table('order_placement_snapshots')->count(),
            'transactions' => DB::table('operational_sales_transactions')->count(),
            'sale_lines' => DB::table('operational_sales_sale_lines')->count(),
        ];
    }

    private function assertNothingWritten(string $variationId, int $stock): void
    {
        $this->assertSame(['orders' => 0, 'payments' => 0, 'snapshots' => 0, 'transactions' => 0, 'sale_lines' => 0], $this->rows());
        $this->assertSame($stock, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity(), 'no stock moved');
        $this->assertNull(DB::table('carts')->where('id', $this->cartId)->value('order_id'), 'the cart is not claimed');
        $this->assertSame(1, DB::table('cart_lines')->where('cart_id', $this->cartId)->count(), 'the cart still has its line');
    }

    private function failingAdapter(): void
    {
        $this->app->bind('payment.adapter.cash_on_delivery', fn () => new class implements PaymentMethodAdapter {
            public function charge(Money $amount, PaymentContext $context): PaymentAttemptResult
            {
                throw new RuntimeException('provider said: card 4111111111111111 declined for jane@example.com');
            }

            public function refund(Payment $original, Money $amount, PaymentContext $context): PaymentRefundAttemptResult
            {
                throw new RuntimeException('not exercised');
            }

            public function isOffline(): bool
            {
                return true;
            }
        });
    }

    // --- Phase 2 containment (B2, O5, O6) -------------------------------------------------------------------------

    public function test_a_payment_step_failure_leaves_the_order_a_pending_payment_and_the_claimed_cart_and_answers_201_with_the_message(): void
    {
        $this->failingAdapter();
        Log::spy();
        $variationId = $this->variation('10.00', 10);
        $this->fillCart($variationId, 2);

        $response = $this->checkout();

        $response->assertStatus(201)
            ->assertJsonPath('already_placed', false)
            ->assertJsonPath('message', 'Your order is placed, but the payment step needs attention. Do not place it again; the shop will contact you.')
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('order.total.minor', 2000);
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(8, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity());
        $this->assertNotNull(DB::table('carts')->where('id', $this->cartId)->value('order_id'), 'the cart stays claimed by the order');

        $payment = DB::table('payments')->sole();
        $this->assertSame('pending', $payment->status);
        $this->assertNull($payment->attempted_at, 'no attempt date, no invented status');

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $message === 'checkout.payment_step_failed'
            && $context['order_id'] === (string) DB::table('orders')->value('id')
            && $context['payment_id'] === (string) $payment->id
            && $context['exception'] === RuntimeException::class
            && ! str_contains(json_encode($context), '4111'))->once();
    }

    public function test_the_payment_failure_message_is_translated_in_the_store_language(): void
    {
        $this->failingAdapter();
        $this->storeLocale('bg');
        $this->fillCart($this->variation());

        $this->checkout()->assertStatus(201)->assertJsonPath('message', 'Поръчката ви е направена, но стъпката с плащането изисква внимание. Не я правете отново; магазинът ще се свърже с вас.');
    }

    public function test_a_retry_after_a_payment_step_failure_returns_the_same_order_with_its_payment_and_no_second_decrement(): void
    {
        $this->failingAdapter();
        $variationId = $this->variation('10.00', 10);
        $this->fillCart($variationId, 3);

        $first = $this->checkout()->assertStatus(201);
        $retry = $this->checkout()->assertStatus(201);

        $retry->assertJsonPath('already_placed', true)
            ->assertJsonPath('order.id', $first->json('order.id'))
            ->assertJsonPath('payment.id', $first->json('payment.id'))
            ->assertJsonPath('payment.status', 'pending');
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(7, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity(), 'decremented once');
    }

    public function test_a_throwing_order_placed_listener_gives_the_same_201_the_order_intact_and_the_payment_recorded(): void
    {
        Log::spy();
        Hook::action('order.placed', function (): void {
            throw new RuntimeException('a merchant listener broke: secret detail');
        });
        $this->fillCart($this->variation('10.00', 10), 2);

        $response = $this->checkout();

        $response->assertStatus(201)
            ->assertJsonPath('already_placed', false)
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonMissingPath('message');
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertNotNull(DB::table('payments')->sole()->attempted_at, 'the attempt was recorded as before');
        $this->assertNotNull(DB::table('carts')->where('id', $this->cartId)->value('order_id'));

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $message === 'checkout.order_placed_listener_failed'
            && $context['exception'] === RuntimeException::class
            && ! str_contains(json_encode($context), 'secret detail'))->once();
        $this->assertStringNotContainsString('secret detail', $response->getContent());
    }

    public function test_a_double_click_is_still_exactly_one_order(): void
    {
        $variationId = $this->variation('10.00', 10);
        $this->fillCart($variationId, 3);

        $first = $this->checkout()->assertStatus(201);
        $second = $this->checkout()->assertStatus(201);

        $this->assertFalse($first->json('already_placed'));
        $this->assertTrue($second->json('already_placed'));
        $this->assertSame($first->json('order.id'), $second->json('order.id'));
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(7, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity());
    }

    // --- controlled refusals instead of 500 (B4) ------------------------------------------------------------------

    private function zeroTotalCart(): string
    {
        app(PromotionRepository::class)->save(Promotion::create(
            code: 'FIX20', discountType: PromotionDiscountType::FIXED_AMOUNT, discountAmount: Money::fromDecimal('20.00', 'EUR'),
        ));
        $variationId = $this->variation('10.00', 10);
        $this->fillCart($variationId, 1);
        $this->putJson('/api/cart/promotion', ['code' => 'FIX20'])->assertStatus(200);

        return $variationId;
    }

    private function mismatchCart(): string
    {
        $good = $this->variation('10.00', 10);
        $bad = $this->variation('4.00', 10);
        $this->fillCart($good, 1);
        $this->fillCart($bad, 1);
        DB::table('pricing_price_list_items')->where('target_id', $bad)->delete();
        app(PriceListItemRepository::class)->save(new PriceListItem(
            null, $this->priceList->id(), PriceListItemTargetType::VARIATION, $bad,
            Price::exclusiveOfTax(Money::fromDecimal('4.00', 'USD'), 0),
        ));

        return $good;
    }

    /** @return array<string, array{string, int, string}> */
    public static function refusals(): array
    {
        return [
            'zero total' => ['zero_total', 422, 'zeroTotalCart'],
            'currency mismatch' => ['currency_mismatch', 422, 'mismatchCart'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_a_priced_refusal_has_a_reason_a_translated_sentence_and_writes_nothing(string $reason, int $status, string $fixture): void
    {
        $variationId = $this->{$fixture}();
        $stock = app(StockLevelRepository::class)->findByVariationId($variationId)->quantity();
        $linesBefore = DB::table('cart_lines')->where('cart_id', $this->cartId)->count();

        $this->storeLocale('en');
        $en = $this->checkout()->assertStatus($status)->assertJsonPath('reason', $reason);
        $this->storeLocale('bg');
        $bg = $this->checkout()->assertStatus($status)->assertJsonPath('reason', $reason);

        $this->assertSame(trans('checkout.'.$reason, [], 'en'), $en->json('message'));
        $this->assertSame(trans('checkout.'.$reason, [], 'bg'), $bg->json('message'));
        $this->assertNotSame($en->json('message'), $bg->json('message'));
        $this->assertSame(['orders' => 0, 'payments' => 0, 'snapshots' => 0, 'transactions' => 0, 'sale_lines' => 0], $this->rows());
        $this->assertSame($stock, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity());
        $this->assertNull(DB::table('carts')->where('id', $this->cartId)->value('order_id'));
        $this->assertSame($linesBefore, DB::table('cart_lines')->where('cart_id', $this->cartId)->count());
    }

    public function test_a_reconciliation_failure_is_a_409_checkout_state_changed_and_writes_nothing(): void
    {
        Log::spy();
        $this->partialMock(SaleLineSnapshotBuilder::class, fn ($mock) => $mock->shouldReceive('buildForCart')->andThrow(new SaleLineOrderReconciliationException('sums differ: 1999 vs 2000')));
        $variationId = $this->variation('10.00', 10);
        $this->fillCart($variationId, 2);

        $response = $this->checkout()->assertStatus(409)->assertJsonPath('reason', 'checkout_state_changed');

        $this->assertSame(trans('checkout.checkout_state_changed', [], 'en'), $response->json('message'));
        $this->assertStringNotContainsString('1999', $response->getContent());
        $this->assertNothingWritten($variationId, 10);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $message === 'checkout.state_changed' && $context['cart_id'] === $this->cartId)->once();
    }

    public function test_a_domain_invariant_reachable_from_input_is_a_422_checkout_invalid_with_the_real_message_only_in_the_log(): void
    {
        Log::spy();
        $variationId = $this->variation('10.00', 10);
        $this->fillCart($variationId, 1);

        // A guest naming a saved address: validation lets it through, the orchestrator's contract refuses it.
        $response = $this->postJson('/api/checkout', [
            'cart_id' => $this->cartId, 'email' => 'guest@example.com', 'recipient_name' => 'Guest Buyer',
            'phone' => '+359888000000', 'payment_method' => 'cash_on_delivery', 'address_id' => self::SCRIPT,
        ])->assertStatus(422)->assertJsonPath('reason', 'checkout_invalid');

        $this->assertSame(trans('checkout.checkout_invalid', [], 'en'), $response->json('message'));
        $this->assertStringNotContainsString('guests have no saved addresses', $response->getContent());
        $this->assertStringNotContainsString('script', $response->getContent());
        $this->assertNothingWritten($variationId, 10);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $message === 'checkout.invalid'
            && $context['cart_id'] === $this->cartId
            && str_contains($context['message'], 'guests have no saved addresses'))->once();
    }

    public function test_an_unknown_exception_is_a_500_json_in_the_store_language_with_no_stack_trace_even_with_debug_on(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        $this->storeLocale('bg');
        $this->partialMock(SaleLineSnapshotBuilder::class, fn ($mock) => $mock->shouldReceive('buildForCart')->andThrow(new RuntimeException('internal detail /var/www/secret.php')));
        $variationId = $this->variation('10.00', 10);
        $this->fillCart($variationId, 1);

        $response = $this->checkout()->assertStatus(500);

        $this->assertSame(['message', 'reason'], array_keys($response->json()));
        $this->assertSame('checkout_failed', $response->json('reason'));
        $this->assertSame(trans('checkout.checkout_failed', [], 'bg'), $response->json('message'));
        foreach (['internal detail', 'secret.php', 'trace', 'exception', 'CheckoutOrchestrator'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
        $this->assertNothingWritten($variationId, 10);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $message === 'checkout.failed'
            && $context['cart_id'] === $this->cartId && $context['exception'] === RuntimeException::class)->once();
    }

    public function test_the_other_refusals_are_translated_fixed_sentences_that_never_echo_input(): void
    {
        $variationId = $this->variation('10.00', 1);
        $this->fillCart($variationId, 1);

        // not enough stock (409)
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 0));
        $stock = $this->checkout()->assertStatus(409)->assertJsonPath('reason', 'insufficient_stock');
        $this->assertSame(trans('checkout.insufficient_stock', [], 'en'), $stock->json('message'));
        $this->assertStringNotContainsString($variationId.'"', $stock->getContent().'"');

        // a cart id that is not anyone's: the same 404 sentence, the typed text never echoed
        $missing = $this->checkout(['cart_id' => self::SCRIPT])->assertStatus(404);
        $this->assertSame(['message' => trans('checkout.cart_not_found', [], 'en')], $missing->json());
        $this->assertStringNotContainsString('script', $missing->getContent());

        // an unknown payment method, typed text never echoed
        $method = $this->checkout(['payment_method' => self::SCRIPT])->assertStatus(422)->assertJsonPath('reason', 'unknown_payment_method');
        $this->assertSame(trans('checkout.unknown_payment_method', [], 'en'), $method->json('message'));
        $this->assertStringNotContainsString('script', $method->getContent());
    }

    // --- the store's locale (B3) ----------------------------------------------------------------------------------

    public function test_validation_and_a_refusal_come_out_in_bulgarian_when_the_store_is_bulgarian_and_the_app_locale_is_restored(): void
    {
        App::setLocale('en');
        $this->storeLocale('bg');
        $this->fillCart($this->variation());

        // The project's own validation messages (the country list, PlainText) are translated in bg; the framework's
        // stock messages (`validation.required` ...) have no bg translation in this project, so they are not used here.
        $validation = $this->checkout(['country' => 'XX', 'phone' => "+359888"])->assertStatus(422);
        $this->assertStringContainsString('Държавата', $validation->json('errors.country.0'));
        $this->assertMatchesRegularExpression('/\p{Cyrillic}/u', $validation->json('errors.phone.0'));

        $refusal = $this->checkout(['cart_id' => '999999999'])->assertStatus(404);
        $this->assertSame(trans('checkout.cart_not_found', [], 'bg'), $refusal->json('message'));

        $this->assertSame('en', App::getLocale(), 'the app locale is restored after the request');
    }

    public function test_validation_and_a_refusal_come_out_in_english_when_the_store_is_english(): void
    {
        App::setLocale('bg');
        $this->storeLocale('en');
        $this->fillCart($this->variation());

        $validation = $this->checkout(['country' => 'XX', 'phone' => "+359888"])->assertStatus(422);
        $this->assertDoesNotMatchRegularExpression('/\p{Cyrillic}/u', $validation->json('errors.country.0'));
        $this->assertDoesNotMatchRegularExpression('/\p{Cyrillic}/u', $validation->json('errors.phone.0'));

        $refusal = $this->checkout(['cart_id' => '999999999'])->assertStatus(404);
        $this->assertSame(trans('checkout.cart_not_found', [], 'en'), $refusal->json('message'));

        $this->assertSame('bg', App::getLocale(), 'the app locale is restored after the request');
    }

    // --- input security -------------------------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function textFields(): array
    {
        return [['cart_id'], ['email'], ['recipient_name'], ['phone'], ['payment_method'], ['city'], ['address_line_1']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('textFields')]
    public function test_a_ten_thousand_character_value_is_a_field_error_not_a_500_and_is_never_echoed(string $field): void
    {
        $this->fillCart($this->variation());
        $huge = str_repeat('Z', 10000);

        $response = $this->checkout([$field => $huge])->assertStatus(422)->assertJsonValidationErrors([$field]);

        $this->assertStringNotContainsString('ZZZZZZZZZZ', $response->getContent());
        $this->assertSame(0, DB::table('orders')->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('textFields')]
    public function test_script_text_in_a_checkout_field_is_never_a_500_and_never_echoed_in_a_reply(string $field): void
    {
        $this->fillCart($this->variation());

        $response = $this->checkout([$field => self::SCRIPT]);

        $this->assertNotSame(500, $response->getStatusCode(), 'script text is data, not an error');

        if ($response->getStatusCode() !== 201) {
            // A refusal never carries typed text back. (A 201 is the stored plain-text order, which the client
            // renders escaped like every other customer string; script text is not a control character.)
            $this->assertStringNotContainsString('<script>', $response->getContent());
        }
    }
    // --- query counts ---------------------------------------------------------------------------------------------

    public function test_query_count_pins_of_the_checkout_request(): void
    {
        $this->fillCart($this->variation('10.00', 10), 1);

        $this->app->forgetScopedInstances();
        $counts = ['total' => 0, 'settings' => 0, 'payments' => 0];
        DB::listen(function ($query) use (&$counts): void {
            $counts['total']++;
            if (str_contains($query->sql, 'site_settings')) {
                $counts['settings']++;
            }
            if (str_contains($query->sql, 'from `payments`') && str_starts_with(strtolower($query->sql), 'select')) {
                $counts['payments']++;
            }
        });

        $this->checkout()->assertStatus(201);
        $fresh = $counts;
        $counts = ['total' => 0, 'settings' => 0, 'payments' => 0];
        $this->checkout()->assertStatus(201)->assertJsonPath('already_placed', true);
        $replay = $counts;

        fwrite(STDERR, sprintf(
            "
[query-count] POST /api/checkout (fresh): %d total, %d settings reads, %d payment selects | (replay): %d total, %d settings reads, %d payment selects
",
            $fresh['total'], $fresh['settings'], $fresh['payments'], $replay['total'], $replay['settings'], $replay['payments'],
        ));

        // Measured on HEAD before stage 4c (same scenario): fresh 32 total / 0 settings reads, replay 5 total / 0 payment selects.
        $this->assertSame(1, $fresh['settings'], 'the store locale: the one new read of a fresh checkout');
        $this->assertSame(56, $fresh['total'], 'fresh: 33 before 4e (32 before 4c + the locale read) + the shipping step, i.e. the quote pipeline once (23 on this 1-line cart)');
        $this->assertSame(1, $replay['payments'], 'a replay reads the stored payment once (stage 4c)');
        $this->assertSame(6, $replay['total'], 'replay: 5 before 4c + the stored-payment select (the locale is already memoised)');
    }
}
