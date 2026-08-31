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
