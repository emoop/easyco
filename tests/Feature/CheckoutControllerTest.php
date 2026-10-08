<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use DateTimeImmutable;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Cart\Persistence\Eloquent\CartModel;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
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
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Checkout HTTP surface — real MySQL, real HTTP calls end to end,
 * including cart construction (via POST /api/cart/lines, never by
 * constructing Cart objects directly), so the real session/guard
 * plumbing CheckoutController::findCurrentCart() depends on is actually
 * exercised.
 *
 * NO EMPTY-CART TEST: there is no HTTP path that leaves an existing cart
 * reachable and empty — adding a line always makes it non-empty, and the
 * only way to remove a line (DELETE /api/cart/lines/{id}) on a single-line
 * cart leaves the cart itself still present but with zero lines... but
 * CheckoutOrchestratorTest.php already covers EmptyCartException directly
 * against the domain layer; contriving an HTTP round trip here just to
 * re-prove the same 422 mapping would test the mapping, not a real gap.
 */
class CheckoutControllerTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;
    private ?PriceList $priceList = null;

    protected function setUp(): void
    {
        parent::setUp();

        // See account-domain-design.md §10 — Sanctum's stateful pipeline
        // needs a recognized Referer to engage the session at all, which
        // the guest cart_token depends on.
        $this->withHeader('Referer', 'http://localhost/');
    }

    private function variationId(): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    private function setPrice(string $variationId, string $decimalAmount): void
    {
        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        $item = new PriceListItem(
            null,
            $this->priceList->id(),
            PriceListItemTargetType::VARIATION,
            $variationId,
            Price::exclusiveOfTax(Money::fromDecimal($decimalAmount, 'EUR'), 0),
        );
        app(PriceListItemRepository::class)->save($item);
    }

    private function setStock(string $variationId, int $quantity): void
    {
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $quantity));
    }

    private function pricedPurchasableVariation(string $decimalAmount = '10.00', int $stock = 10): string
    {
        $variationId = $this->variationId();
        $this->setPrice($variationId, $decimalAmount);
        $this->setStock($variationId, $stock);

        return $variationId;
    }

    private function createPromotion(
        string $code,
        ?DateTimeImmutable $validFrom = null,
        ?DateTimeImmutable $validUntil = null,
        int $percentageBasisPoints = 1000,
    ): Promotion {
        $promotion = Promotion::create(
            code: $code,
            discountType: PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: $percentageBasisPoints,
            validFrom: $validFrom,
            validUntil: $validUntil,
        );
        app(PromotionRepository::class)->save($promotion);

        return $promotion;
    }

    private function loggedInAccount(string $email = 'user@example.com'): AccountModel
    {
        $account = Account::register($email, 'hashed-password');
        app(AccountRepository::class)->save($account);
        $model = AccountModel::findOrFail($account->id());

        $this->actingAs($model, 'customer');

        return $model;
    }

    private string $cartId = '';

    private function addLineViaHttp(string $variationId, int $quantity = 1): void
    {
        // The cart the "page" would now be displaying (cart-domain-design.md §14.2).
        $this->cartId = (string) $this->postJson('/api/cart/lines', [
            'variation_id' => $variationId,
            'quantity' => $quantity,
        ])->assertStatus(201)->json('cart_id');
    }

    private function checkoutPayload(array $overrides = []): array
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
        ], $overrides);
    }

    public function test_a_full_guest_checkout_with_a_fresh_address_places_a_real_order(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 2);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('already_placed', false);
        $response->assertJsonPath('order.email', 'guest@example.com');
        $response->assertJsonPath('order.subtotal.minor', 2000);
        $response->assertJsonPath('order.total.minor', 2000);
        $response->assertJsonPath('payment.method', 'cash_on_delivery');
        $response->assertJsonPath('payment.status', 'pending');

        $orderId = $response->json('order.id');
        $this->assertNotNull(OrderModel::find($orderId));
        $this->assertSame(8, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity());
    }

    public function test_a_logged_in_checkout_using_a_saved_address_id_carries_the_account_id(): void
    {
        $account = $this->loggedInAccount();
        $accountId = (string) $account->id;

        $addressResponse = $this->postJson('/api/addresses', [
            'delivery_type' => 'street_address',
            'recipient_name' => 'Ivan Ivanov',
            'phone' => '+359888111222',
            'country' => 'BG',
            'city' => 'Sofia',
            'address_line_1' => 'Graf Ignatiev 5',
        ]);
        $addressResponse->assertStatus(201);
        $addressId = $addressResponse->json('id');

        $variationId = $this->pricedPurchasableVariation('15.00', 5);
        $this->addLineViaHttp($variationId, 1);

        // address_id PROHIBITS the typed-address fields (CheckoutController::validationRules()),
        // and the shared helper's defaults include them — so this payload starts from the
        // helper and drops them, exactly like a real client choosing a saved address.
        $payload = $this->checkoutPayload([
            'email' => $account->email,
            'recipient_name' => 'Ivan Ivanov',
            'phone' => '+359888111222',
            'payment_method' => 'bank_transfer',
            'address_id' => $addressId,
        ]);

        unset($payload['delivery_type'], $payload['country'], $payload['city'], $payload['address_line_1']);

        $response = $this->postJson('/api/checkout', $payload);

        $response->assertStatus(201);
        $orderId = $response->json('order.id');
        $order = OrderModel::findOrFail($orderId);
        $this->assertSame($accountId, (string) $order->account_id);
        $this->assertSame((string) $addressId, (string) $order->address_id);
    }

    // --- the delivery country, owner decision D1 (stage 3.0b) -----------------------------------

    /** @return array<string, mixed> a fresh PICKUP_POINT delivery (no street fields) */
    private function pickupPayload(array $overrides = []): array
    {
        $payload = $this->checkoutPayload(array_merge([
            'delivery_type' => 'pickup_point',
            'country' => 'bg',
            'carrier_code' => 'econt',
            'pickup_point_reference' => 'office-1234',
            'settlement' => 'Plovdiv',
        ], $overrides));
        unset($payload['city'], $payload['address_line_1']);

        return $payload;
    }

    public function test_checkout_copies_a_pickup_points_country_onto_the_order_and_the_http_layer_uppercases_it(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $response = $this->postJson('/api/checkout', $this->pickupPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('order.country', 'BG');
        $response->assertJsonPath('order.delivery_type', 'pickup_point');
        $this->assertSame('BG', OrderModel::findOrFail($response->json('order.id'))->country);
    }

    public function test_checkout_copies_the_country_of_a_saved_pickup_point_address_onto_the_order(): void
    {
        $this->loggedInAccount();
        $addressId = $this->postJson('/api/addresses', [
            'delivery_type' => 'pickup_point',
            'recipient_name' => 'Ivan Ivanov',
            'phone' => '+359888111222',
            'country' => 'GR',
            'carrier_code' => 'speedy',
            'pickup_point_reference' => 'office-9',
            'settlement' => 'Athens',
        ])->assertStatus(201)->json('id');

        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $payload = $this->checkoutPayload(['address_id' => $addressId]);
        unset($payload['delivery_type'], $payload['country'], $payload['city'], $payload['address_line_1']);

        $response = $this->postJson('/api/checkout', $payload)->assertStatus(201);

        $this->assertSame('GR', OrderModel::findOrFail($response->json('order.id'))->country);
    }

    public function test_a_pickup_point_checkout_without_a_country_returns_422_and_places_nothing(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $payload = $this->pickupPayload();
        unset($payload['country']);

        $this->postJson('/api/checkout', $payload)->assertStatus(422)->assertJsonValidationErrors(['country']);
        $this->assertSame(0, OrderModel::count());
    }

    public function test_an_unknown_country_is_refused_at_checkout_for_both_delivery_types_and_xk_is_accepted(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $this->postJson('/api/checkout', $this->pickupPayload(['country' => 'ZZ']))->assertStatus(422)->assertJsonValidationErrors(['country']);
        $this->postJson('/api/checkout', $this->checkoutPayload(['country' => 'ZZ']))->assertStatus(422)->assertJsonValidationErrors(['country']);
        $this->assertSame(0, OrderModel::count());

        $this->postJson('/api/checkout', $this->pickupPayload(['country' => 'xk']))->assertStatus(201)->assertJsonPath('order.country', 'XK');
    }

    public function test_a_typed_country_together_with_a_saved_address_id_is_still_refused(): void
    {
        $this->loggedInAccount();
        $addressId = $this->postJson('/api/addresses', [
            'delivery_type' => 'street_address', 'recipient_name' => 'Ivan Ivanov', 'phone' => '+359888111222',
            'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'Graf Ignatiev 5',
        ])->assertStatus(201)->json('id');
        $this->addLineViaHttp($this->pricedPurchasableVariation('10.00', 10), 1);

        $payload = $this->checkoutPayload(['address_id' => $addressId]);
        unset($payload['delivery_type'], $payload['city'], $payload['address_line_1']);

        $this->postJson('/api/checkout', $payload)->assertStatus(422)->assertJsonValidationErrors(['address_id']);
    }

    public function test_a_saved_address_without_a_country_is_refused_422_before_anything_is_written_and_the_cart_stays_usable(): void
    {
        $account = $this->loggedInAccount();
        $addressId = $this->postJson('/api/addresses', [
            'delivery_type' => 'pickup_point', 'recipient_name' => 'Ivan Ivanov', 'phone' => '+359888111222',
            'country' => 'BG', 'carrier_code' => 'econt', 'pickup_point_reference' => 'office-1', 'settlement' => 'Varna',
        ])->assertStatus(201)->json('id');
        // A historical pickup point, saved before the country became mandatory.
        \Illuminate\Support\Facades\DB::table('addresses')->where('id', $addressId)->update(['country' => null]);

        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $payload = $this->checkoutPayload(['address_id' => $addressId]);
        unset($payload['delivery_type'], $payload['country'], $payload['city'], $payload['address_line_1']);

        \Illuminate\Support\Facades\Log::spy();
        $this->postJson('/api/checkout', $payload)
            ->assertStatus(422)
            ->assertJsonPath('reason', 'address_incomplete')
            ->assertJsonPath('message', __('delivery.address_incomplete'));

        // The warning names the address id and nothing personal.
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $context === ['address_id' => (string) $addressId])
            ->once();

        // Nothing written; the cart is untouched.
        $db = fn (string $t) => \Illuminate\Support\Facades\DB::table($t)->count();
        $this->assertSame(0, $db('orders'));
        $this->assertSame(0, $db('payments'));
        $this->assertSame(0, $db('operational_sales_transactions'));
        $this->assertSame(0, $db('operational_sales_sale_lines'));
        $this->assertNull(\Illuminate\Support\Facades\DB::table('carts')->where('id', $this->cartId)->value('order_id'));
        $this->assertSame(10, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity());

        // The customer updates the address; the same cart now checks out.
        $this->putJson("/api/addresses/{$addressId}", [
            'delivery_type' => 'pickup_point', 'recipient_name' => 'Ivan Ivanov', 'phone' => '+359888111222',
            'country' => 'bg', 'carrier_code' => 'econt', 'pickup_point_reference' => 'office-1', 'settlement' => 'Varna',
        ])->assertStatus(200);

        $response = $this->postJson('/api/checkout', $payload)->assertStatus(201);
        $this->assertSame('BG', OrderModel::findOrFail($response->json('order.id'))->country);
    }

    public function test_the_incomplete_address_message_exists_in_english_and_bulgarian(): void
    {
        $this->assertNotSame('delivery.address_incomplete', __('delivery.address_incomplete', [], 'en'));
        $this->assertNotSame('delivery.address_incomplete', __('delivery.address_incomplete', [], 'bg'));
        $this->assertNotSame(__('delivery.address_incomplete', [], 'en'), __('delivery.address_incomplete', [], 'bg'));
    }

    public function test_checkout_without_a_cart_id_returns_422(): void
    {
        $payload = $this->checkoutPayload();
        unset($payload['cart_id']);

        // REQUIRED, not optional — cart-domain-design.md §14.2: without it a
        // sequential second click would find no live cart and 404 instead of
        // replaying, so the protection must never depend on the client sending it.
        $this->postJson('/api/checkout', $payload)->assertStatus(422);
    }

    public function test_checkout_with_an_unknown_cart_id_returns_404(): void
    {
        $this->postJson('/api/checkout', $this->checkoutPayload([
            'cart_id' => '0193f0d0-0000-7000-8000-000000000000',
        ]))->assertStatus(404);
    }

    public function test_double_submit_returns_201_twice_with_the_second_marked_already_placed(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 3);

        $payload = $this->checkoutPayload();

        $first = $this->postJson('/api/checkout', $payload);
        $second = $this->postJson('/api/checkout', $payload);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $first->assertJsonPath('already_placed', false);
        $second->assertJsonPath('already_placed', true);
        // Stage 4c: the replay carries the stored payment (it used to be null); the same row, never a second charge.
        $this->assertSame($first->json('payment'), $second->json('payment'));
        $this->assertSame($first->json('order.id'), $second->json('order.id'));
        $this->assertSame(1, OrderModel::count());
    }

    public function test_an_expired_promotion_code_returns_422_and_creates_no_order(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);
        $this->createPromotion('EXPIRED10', validUntil: new DateTimeImmutable('2020-01-01'));
        $this->putJson('/api/cart/promotion', ['code' => 'EXPIRED10'])->assertStatus(200);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload());

        $response->assertStatus(422);
        $response->assertJsonPath('reason', 'promotion_no_longer_valid');
        $this->assertSame(0, OrderModel::count());
    }

    public function test_insufficient_stock_at_checkout_returns_409_and_creates_no_order(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 5);
        $this->addLineViaHttp($variationId, 5);

        // Sold out by another concurrent transaction after add-to-cart.
        $this->setStock($variationId, 2);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload());

        $response->assertStatus(409);
        $response->assertJsonPath('reason', 'insufficient_stock');
        $this->assertSame(0, OrderModel::count());
    }

    /**
     * Deliberately different from CheckoutOrchestratorTest's own
     * equivalent test, which still asserts the Order survives: that one
     * exercises the orchestrator directly, where the Phase-1/Phase-2
     * boundary genuinely does leave the order standing. Both are
     * correct for their own layer — the controller's job is specifically
     * to make sure real HTTP traffic never reaches that state, by
     * rejecting an unknown payment_method BEFORE Phase 1 ever runs. An
     * unknown method reaching place() itself would leave a real,
     * unpayable Order behind (committed, no Payment, cart claimed) that
     * a retry could never fix, since a retry would just hit the
     * idempotent-replay path. This test proves that hole is closed.
     */
    public function test_an_unknown_payment_method_returns_422_with_nothing_committed(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload(['payment_method' => 'bitcoin']));

        $response->assertStatus(422);
        $response->assertJsonPath('reason', 'unknown_payment_method');

        $this->assertSame(0, OrderModel::count());
        $this->assertSame(10, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity());

        $cartId = (string) CartModel::firstOrFail()->id;
        $this->assertNull(app(CartRepository::class)->findOrderIdForCart($cartId));
    }

    public function test_a_checkout_rejected_for_an_unknown_payment_method_is_genuinely_retryable(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $rejected = $this->postJson('/api/checkout', $this->checkoutPayload(['payment_method' => 'bitcoin']));
        $rejected->assertStatus(422);

        $retried = $this->postJson('/api/checkout', $this->checkoutPayload(['payment_method' => 'cash_on_delivery']));

        $retried->assertStatus(201);
        $retried->assertJsonPath('already_placed', false);
        $this->assertNotNull($retried->json('payment'));
        $this->assertSame(1, OrderModel::count());
    }

    public function test_missing_email_returns_422(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $payload = $this->checkoutPayload();
        unset($payload['email']);

        $this->postJson('/api/checkout', $payload)->assertStatus(422);
    }

    public function test_address_id_together_with_delivery_type_returns_422(): void
    {
        $account = $this->loggedInAccount();

        $addressResponse = $this->postJson('/api/addresses', [
            'delivery_type' => 'street_address',
            'recipient_name' => 'Ivan Ivanov',
            'phone' => '+359888111222',
            'country' => 'BG',
            'city' => 'Sofia',
            'address_line_1' => 'Graf Ignatiev 5',
        ]);
        $addressId = $addressResponse->json('id');

        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $response = $this->postJson('/api/checkout', [
            'email' => $account->email,
            'recipient_name' => 'Ivan Ivanov',
            'phone' => '+359888111222',
            'payment_method' => 'cash_on_delivery',
            'address_id' => $addressId,
            'delivery_type' => 'street_address',
            'country' => 'BG',
            'city' => 'Sofia',
            'address_line_1' => 'Graf Ignatiev 5',
        ]);

        $response->assertStatus(422);
    }

    // --- Input hardening: length and content limits (input-hardening pass 1) -----

    /**
     * Every text field this endpoint stores in a varchar(255) column plus the
     * phone, whose interim bound is 32 characters. The limit is in CHARACTERS,
     * never bytes: a 255-character Cyrillic value is 510 bytes and must still be
     * accepted, which is exactly what the Cyrillic values below prove.
     *
     * @return array<string, array{string, int}>
     */
    public static function hardenedTextFields(): array
    {
        return [
            'email' => ['email', 255],
            'recipient_name' => ['recipient_name', 255],
            'phone' => ['phone', 32],
            'city' => ['city', 255],
            'postal_code' => ['postal_code', 255],
            'address_line_1' => ['address_line_1', 255],
            'address_line_2' => ['address_line_2', 255],
        ];
    }

    /** The value a test uses for $field when it wants exactly $characters of it. */
    private function valueOfLength(string $field, int $characters): string
    {
        return $field === 'email' ? $this->emailOfLength($characters) : str_repeat('я', $characters);
    }

    /**
     * The longest value the field's own rules actually accept: $limit characters
     * of Cyrillic for the free-text fields, 32 for the phone, and 255 characters
     * of ASCII for the email — an address, unlike a name, cannot be Cyrillic.
     */
    private function longestAcceptedValue(string $field, int $limit): string
    {
        return $field === 'email' ? $this->emailOfLength($limit) : str_repeat('я', $limit);
    }

    /**
     * A syntactically valid address of exactly $characters ASCII characters: a
     * 64-character local part, then short (at most 10-character) domain labels
     * and a three-character final one.
     *
     * THE BUILDER IS NOT DECORATION: `email` carries NO length bound of its own
     * — a 266-character address built this way passes it — so a long-enough
     * address is exactly the input that would reach orders.email's varchar(255)
     * and come back as a 500 without max:255. Labels of 63 characters are
     * deliberately avoided: the validator refuses those constructions whatever
     * their total length, which would make this test prove nothing.
     */
    private function emailOfLength(int $characters): string
    {
        $localLength = 65;                       // 64 'a's and the '@'
        $finalLabel = 'com';                     // its own leading dot is counted below
        $domainCharacters = $characters - $localLength - mb_strlen($finalLabel) - 1;

        $labels = (int) ceil(($domainCharacters + 1) / 11);
        $labelCharacters = $domainCharacters - ($labels - 1);
        $base = intdiv($labelCharacters, $labels);
        $longer = $labelCharacters % $labels;

        $domain = [];

        for ($i = 0; $i < $labels; $i++) {
            $domain[] = str_repeat(chr(98 + ($i % 24)), $base + ($i < $longer ? 1 : 0));
        }

        $email = str_repeat('a', 64).'@'.implode('.', $domain).'.'.$finalLabel;

        $this->assertSame($characters, mb_strlen($email), 'The builder must produce an address of exactly the requested length.');

        return $email;
    }

    #[DataProvider('hardenedTextFields')]
    public function test_one_character_too_many_is_a_422_field_error_and_writes_nothing(string $field, int $limit): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload([
            $field => $this->valueOfLength($field, $limit + 1),
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([$field]);
        $this->assertSame(0, OrderModel::count());
        $this->assertSame(10, app(StockLevelRepository::class)->findByVariationId($variationId)->quantity());
    }

    #[DataProvider('hardenedTextFields')]
    public function test_a_value_within_the_limit_is_accepted_and_stored_unchanged(string $field, int $limit): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $value = $this->longestAcceptedValue($field, $limit);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload([$field => $value]));

        $response->assertStatus(201);

        $order = OrderModel::findOrFail($response->json('order.id'));

        $this->assertSame($value, $order->{$field});
        $this->assertSame(mb_strlen($value), mb_strlen($order->{$field}), 'The bound is in characters, not bytes.');
        $this->assertLessThanOrEqual($limit, mb_strlen($value));
    }

    /**
     * `email` carries no length bound of its own: a 256-character address built
     * from short domain labels passes it, and only max:255 keeps it out of
     * orders.email's varchar(255). This pins that the refusal is the WIDTH limit
     * and not the address's shape — the 500 this rule exists to prevent.
     */
    public function test_a_256_character_email_is_refused_by_the_width_limit_not_the_email_rule(): void
    {
        $email = $this->emailOfLength(256);

        $this->assertTrue(
            Validator::make(['email' => $email], ['email' => 'email'])->passes(),
            'The address rule itself accepts this address, so only the width limit refuses it.'
        );

        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload(['email' => $email]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
        $this->assertSame(0, OrderModel::count());
    }

    /** @return array<string, array{string}> */
    public static function refusedCharacters(): array
    {
        return [
            'a newline' => ["line\nbreak"],
            'a NUL byte' => ["nul\0byte"],
            'a tab' => ["tab\tseparated"],
            'a right-to-left override' => ['Prague'."\u{202E}".'Ames'],
        ];
    }

    /**
     * Every text field crossed with every refused character, built here rather
     * than with two attributes: PHPUnit runs one data provider at a time, it
     * does not take their product.
     *
     * @return array<string, array{string, string}>
     */
    public static function refusedCharacterCases(): array
    {
        $cases = [];

        foreach (array_keys(self::hardenedTextFields()) as $field) {
            foreach (self::refusedCharacters() as $description => [$value]) {
                $cases["{$field} carrying {$description}"] = [$field, $value];
            }
        }

        return $cases;
    }

    /**
     * Every text field, every refused character: a single-line field that
     * carries a line break, a NUL, a tab or a bidirectional override is a field
     * error, and nothing is written.
     */
    #[DataProvider('refusedCharacterCases')]
    public function test_a_control_or_bidirectional_character_is_a_422_field_error(string $field, string $value): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload([$field => $value]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([$field]);
        $this->assertSame(0, OrderModel::count());
    }

    /** @return array<string, array{string}> */
    public static function specialCharacterValues(): array
    {
        return [
            'a script tag' => ['<script>alert(1)</script>'],
            'an apostrophe, an ampersand and quotes' => ["O'Brien & Sons \"Ltd\""],
            'an ampersand entity' => ['A &amp; B'],
            'Cyrillic with punctuation' => ['ул. Витоша 1, София'],
        ];
    }

    /**
     * Special characters are NOT rejected or stripped: they are stored exactly
     * as typed, and escaped only where they are displayed (the admin page test
     * below is the second half of that promise).
     */
    #[DataProvider('specialCharacterValues')]
    public function test_special_characters_are_accepted_and_stored_unchanged(string $value): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload([
            'recipient_name' => $value,
            'address_line_1' => $value,
        ]));

        $response->assertStatus(201);
        $response->assertJsonPath('order.recipient_name', $value);

        $order = OrderModel::findOrFail($response->json('order.id'));

        $this->assertSame($value, $order->recipient_name);
        $this->assertSame($value, $order->address_line_1);
    }

    /**
     * The whole promise in one round trip: markup typed by a customer is stored
     * VERBATIM (the API never strips or encodes it) and is ESCAPED when the
     * merchant's own order page renders it, so it can neither be executed nor
     * break the page out of its markup.
     *
     * The recipient name reaches the page as the client name (a guest checkout
     * creates its Client with the recipient's own name), and the address line
     * reaches it in the delivery block, which the resource escapes line by line
     * while it inserts its own <br> separators.
     */
    public function test_customer_markup_is_stored_raw_and_escaped_on_the_admin_order_page(): void
    {
        $script = '<script>alert(1)</script>';

        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload([
            'recipient_name' => $script,
            'address_line_1' => $script,
        ]));

        $response->assertStatus(201);

        $orderId = (string) $response->json('order.id');
        $order = OrderModel::findOrFail($orderId);

        $this->assertSame($script, $order->recipient_name);
        $this->assertSame($script, $order->address_line_1);

        $this->actingAsAdministrator();

        $page = Livewire::test(ViewOrder::class, ['record' => $orderId]);

        $page->assertOk();
        $page->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;');
        $page->assertDontSeeHtml('<script>alert(1)');
    }

    // --- Input hardening pass 2: the pickup-point fields and the two echoed ids ----------

    /**
     * The three fields only a PICKUP_POINT delivery carries — all three stored in
     * varchar(255) columns on orders. Their required_if/prohibited_if shape is
     * untouched; what these tests pin is the width and the character set.
     *
     * @return array<string, array{string, int}>
     */
    public static function hardenedPickupPointFields(): array
    {
        return [
            'carrier_code' => ['carrier_code', 255],
            'pickup_point_reference' => ['pickup_point_reference', 255],
            'settlement' => ['settlement', 255],
        ];
    }

    #[DataProvider('hardenedPickupPointFields')]
    public function test_a_pickup_point_value_at_the_limit_is_accepted_and_stored_unchanged(string $field, int $limit): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $value = str_repeat('я', $limit);

        $response = $this->postJson('/api/checkout', $this->pickupPayload([$field => $value]));

        $response->assertStatus(201);
        $response->assertJsonPath("order.{$field}", $value);

        $order = OrderModel::findOrFail($response->json('order.id'));

        $this->assertSame($value, $order->{$field});
        $this->assertSame($limit, mb_strlen($order->{$field}), 'The bound is in characters, not bytes.');
    }

    #[DataProvider('hardenedPickupPointFields')]
    public function test_a_pickup_point_value_one_character_too_many_is_a_422_field_error_and_places_no_order(string $field, int $limit): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $response = $this->postJson('/api/checkout', $this->pickupPayload([
            $field => str_repeat('я', $limit + 1),
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([$field]);
        $this->assertSame(0, OrderModel::count());
    }

    /**
     * Every pickup-point field crossed with every refused character (the same
     * character list the pass-1 section above uses), built here rather than with
     * two attributes: PHPUnit runs one data provider at a time.
     *
     * @return array<string, array{string, string}>
     */
    public static function refusedPickupPointCharacterCases(): array
    {
        $cases = [];

        foreach (array_keys(self::hardenedPickupPointFields()) as $field) {
            foreach (self::refusedCharacters() as $description => [$value]) {
                $cases["{$field} carrying {$description}"] = [$field, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('refusedPickupPointCharacterCases')]
    public function test_a_control_or_bidirectional_character_in_a_pickup_point_field_is_a_422_and_places_no_order(string $field, string $value): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $response = $this->postJson('/api/checkout', $this->pickupPayload([$field => $value]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([$field]);
        $this->assertSame(0, OrderModel::count());
    }

    public function test_a_256_character_cart_id_is_a_422_field_error_and_places_no_order(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload([
            'cart_id' => str_repeat('я', 256),
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cart_id']);
        $this->assertSame(0, OrderModel::count());
    }

    /**
     * A 255-character cart id is a legitimate VALUE as far as this layer is
     * concerned — it is the CART that is unknown, which is the orchestrator's own
     * 404 (the answer any unknown id gets). This is what pins that max:255
     * refuses a width and not a shape.
     */
    public function test_a_255_character_cart_id_passes_the_width_rule_and_is_a_404_for_the_unknown_cart(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload([
            'cart_id' => str_repeat('я', 255),
        ]));

        $response->assertStatus(404);
        $response->assertJsonMissingValidationErrors(['cart_id']);
        $this->assertSame(0, OrderModel::count());
    }

    public function test_a_256_character_payment_method_is_a_422_field_error_and_places_no_order(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload([
            'payment_method' => str_repeat('я', 256),
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payment_method']);
        $this->assertSame(0, OrderModel::count());
    }

    /**
     * At the limit the width rule lets the value through, and what refuses it is
     * the adapter resolver's own unknown-method answer — a 422 that is NOT a
     * validation error, which is exactly the difference max:255 draws.
     */
    public function test_a_255_character_payment_method_is_not_a_validation_failure_but_an_unknown_method(): void
    {
        $variationId = $this->pricedPurchasableVariation('10.00', 10);
        $this->addLineViaHttp($variationId, 1);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload([
            'payment_method' => str_repeat('я', 255),
        ]));

        $response->assertStatus(422);
        $response->assertJsonMissingValidationErrors(['payment_method']);
        $response->assertJsonPath('reason', 'unknown_payment_method');
        $this->assertSame(0, OrderModel::count());
    }
}



