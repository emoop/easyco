<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Contracts\PasswordHasher;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLine;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Cart\Persistence\Eloquent\CartModel;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartMergeOnLoginTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;
    private ?PriceList $priceList = null;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function setStock(string $variationId, int $quantity): void
    {
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $quantity));
    }

    /**
     * The guest-side additions in this test go through the real HTTP
     * endpoint, which live-resolves a price (cart-domain-design.md
     * §4) — every variation added that way needs a real PriceListItem
     * seeded first, or PriceResolver::resolve() throws.
     */
    private function setPrice(string $variationId, string $decimalAmount = '10.00'): void
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

    /** A registered account with a known plaintext password, ready to log in via real HTTP. */
    private function registeredAccount(string $email = 'user@example.com', string $password = 'password123'): string
    {
        $account = Account::register($email, app(PasswordHasher::class)->hash($password));
        app(AccountRepository::class)->save($account);

        return $account->id();
    }

    private function login(string $email = 'user@example.com', string $password = 'password123'): void
    {
        $this->postJson('/api/account/login', ['email' => $email, 'password' => $password])
            ->assertStatus(200);
    }

    private function addToGuestCart(string $variationId, int $quantity): void
    {
        $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => $quantity])
            ->assertStatus(201);
    }

    /**
     * A COMPLETED guest checkout in this session — the cart is left claimed, which is
     * the state cart-domain-design.md §14.3 is about.
     *
     * @return array{cart_id: string, order_id: string}
     */
    private function completeGuestCheckout(string $variationId, string $email = 'guest@example.com'): array
    {
        $cartId = (string) $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1])
            ->assertStatus(201)->json('cart_id');

        $orderId = (string) $this->postJson('/api/checkout', [
            'cart_id' => $cartId,
            'email' => $email,
            'recipient_name' => 'Guest Buyer',
            'phone' => '+359888000000',
            'payment_method' => 'cash_on_delivery',
            'delivery_type' => 'street_address',
            'country' => 'BG',
            'city' => 'Sofia',
            'address_line_1' => 'Vitosha Blvd 1',
        ])->assertStatus(201)->json('order.id');

        return ['cart_id' => $cartId, 'order_id' => $orderId];
    }

    public function test_a_claimed_guest_cart_is_neither_merged_into_the_account_cart_nor_deleted(): void
    {
        $variationId = $this->variationId();
        $this->setPrice($variationId);
        $this->setStock($variationId, 5);

        $checkout = $this->completeGuestCheckout($variationId);
        $accountId = $this->registeredAccount('claimed-guest@example.com');

        $this->login('claimed-guest@example.com');

        // Nothing was merged: the account has no live cart at all, because the guest's
        // bought lines must never be re-added to it (cart-domain-design.md §14.3).
        $this->assertNull(app(CartRepository::class)->findByAccountId($accountId));

        // The claimed row survives, with its claim — which is what a late replay
        // (and the sandbox's own confirmation) depends on.
        $this->assertNotNull(CartModel::find($checkout['cart_id']));
        $this->assertSame($checkout['order_id'], app(CartRepository::class)->findOrderIdForCart($checkout['cart_id']));

        // ...and the session token is forgotten, so the claimed cart can never become
        // "current" again through the session either.
        $this->assertNull(session('cart_token'));
    }

    public function test_a_claimed_account_cart_is_not_merged_into_so_the_guest_lines_land_in_a_new_one(): void
    {
        $variationId = $this->variationId();
        $this->setPrice($variationId);
        $this->setStock($variationId, 5);

        $accountId = $this->registeredAccount('bought-before@example.com');
        $this->login('bought-before@example.com');

        // The account buys once — its cart is now claimed, and no longer the current one.
        $checkout = $this->completeGuestCheckout($variationId, 'bought-before@example.com');
        $this->assertNull(app(CartRepository::class)->findByAccountId($accountId));

        // A guest session adds something, then logs in.
        $this->postJson('/api/account/logout')->assertStatus(204);
        $this->addToGuestCart($variationId, 2);
        $this->login('bought-before@example.com');

        // The merge target was a NEW account cart, not the claimed one: the guest's
        // lines are there, and the claimed cart still holds its own.
        $merged = app(CartRepository::class)->findByAccountId($accountId);
        $this->assertNotNull($merged);
        $this->assertNotSame($checkout['cart_id'], $merged->id());
        $this->assertCount(1, $merged->lines());
        $this->assertSame(2, $merged->lines()[0]->quantity());
        $this->assertSame($checkout['order_id'], app(CartRepository::class)->findOrderIdForCart($checkout['cart_id']));
    }

    public function test_guest_cart_merges_into_an_existing_account_cart_rather_than_replacing_it(): void
    {
        $accountVariation = $this->variationId();
        $this->setStock($accountVariation, 10);
        $guestVariation = $this->variationId();
        $this->setStock($guestVariation, 10);
        $this->setPrice($guestVariation);

        $accountId = $this->registeredAccount();
        $accountCart = Cart::forAccount($accountId, new DateTimeImmutable('+30 days'));
        $accountCart->addLine(new CartLine(null, '', $accountVariation, 1));
        app(CartRepository::class)->save($accountCart);

        $this->addToGuestCart($guestVariation, 2);

        $this->login();

        $mergedCart = app(CartRepository::class)->findByAccountId($accountId);
        $this->assertNotNull($mergedCart);
        $this->assertCount(2, $mergedCart->lines());

        $byVariation = [];
        foreach ($mergedCart->lines() as $line) {
            $byVariation[$line->variationId()] = $line->quantity();
        }
        $this->assertSame(1, $byVariation[$accountVariation]);
        $this->assertSame(2, $byVariation[$guestVariation]);
    }

    public function test_overlapping_variation_quantities_are_summed(): void
    {
        $sharedVariation = $this->variationId();
        $this->setStock($sharedVariation, 100);
        $this->setPrice($sharedVariation);

        $accountId = $this->registeredAccount();
        $accountCart = Cart::forAccount($accountId, new DateTimeImmutable('+30 days'));
        $accountCart->addLine(new CartLine(null, '', $sharedVariation, 3));
        app(CartRepository::class)->save($accountCart);

        $this->addToGuestCart($sharedVariation, 4);

        $this->login();

        $mergedCart = app(CartRepository::class)->findByAccountId($accountId);
        $this->assertCount(1, $mergedCart->lines());
        $this->assertSame(7, $mergedCart->lines()[0]->quantity());
    }

    public function test_a_summed_quantity_exceeding_available_stock_is_clamped_not_rejected(): void
    {
        $sharedVariation = $this->variationId();
        $this->setStock($sharedVariation, 5);
        $this->setPrice($sharedVariation);

        $accountId = $this->registeredAccount();
        $accountCart = Cart::forAccount($accountId, new DateTimeImmutable('+30 days'));
        $accountCart->addLine(new CartLine(null, '', $sharedVariation, 3));
        app(CartRepository::class)->save($accountCart);

        $this->addToGuestCart($sharedVariation, 4);

        // Login must succeed even though 3 + 4 = 7 exceeds the 5
        // available — the merge clamps, it never fails the login.
        $this->login();

        $mergedCart = app(CartRepository::class)->findByAccountId($accountId);
        $this->assertCount(1, $mergedCart->lines());
        $this->assertSame(5, $mergedCart->lines()[0]->quantity());
    }

    public function test_the_guest_cart_row_is_deleted_after_a_successful_merge(): void
    {
        $guestVariation = $this->variationId();
        $this->setStock($guestVariation, 10);
        $this->setPrice($guestVariation);

        $this->registeredAccount();
        $this->addToGuestCart($guestVariation, 1);

        $this->assertSame(1, CartModel::count());

        $this->login();

        // Exactly one cart remains: the account's (merged) cart — the
        // guest row is gone, not left behind as an orphan.
        $this->assertSame(1, CartModel::count());
        $this->assertNull(CartModel::whereNull('account_id')->first());
    }

    public function test_a_guest_with_no_cart_at_all_logs_in_without_error_and_creates_no_extra_cart(): void
    {
        $this->registeredAccount();

        $this->login();

        $this->assertSame(0, CartModel::count());
    }
}
