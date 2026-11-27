<?php

namespace Tests\Feature\Account;

use App\Mail\MailTemplates;
use App\Services\GuestOrderAttacher;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Extensibility\Hook;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\ProvidesCheckoutShipping;
use Tests\Feature\Account\Concerns\DrivesAccountApi;
use Tests\Feature\Account\Concerns\MakesAccountOrders;
use Tests\TestCase;

/** ACC-2 decision 4: guest orders are attached to an account by email; and the soft session check (decision 5). */
class AccountGuestOrderAttachTest extends TestCase
{
    use DrivesAccountApi;
    use MakesAccountOrders;
    use ProvidesCheckoutShipping;
    use RefreshDatabase;

    /** @var list<array<int, mixed>> */
    private array $attached = [];

    private static int $variationCounter = 0;

    private ?PriceList $priceList = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driveAccountApi();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Hook::action('account.orders_attached', function (...$args): void {
            $this->attached[] = $args;
        });
    }

    // ---- on verification ------------------------------------------------------------------------------------------------------

    /** ★ two guest orders of the address (one with another case and spaces) move; another address and an order of another account do not; the hook fires once with 2; a replay moves nothing */
    public function test_verifying_moves_only_the_guest_orders_of_the_address_and_fires_the_hook_once(): void
    {
        $account = $this->unverifiedAccount('buyer@example.com');
        $other = $this->verifiedAccount('someone@example.com');

        $a = $this->makeOrder('buyer@example.com');
        $b = $this->makeOrder('  Buyer@Example.COM ');
        $elsewhere = $this->makeOrder('else@example.com');
        $taken = $this->makeOrder('buyer@example.com', (string) $other->getKey());

        $this->postJson('/api/account/verify/resend', ['email' => 'buyer@example.com']);
        $params = $this->linkParams($this->mails(MailTemplates::ACCOUNT_VERIFY, 'buyer@example.com')[0]);

        $this->postJson('/api/account/verify', $params)->assertStatus(200);

        $this->assertSame((string) $account->getKey(), $this->orderAccountId($a));
        $this->assertSame((string) $account->getKey(), $this->orderAccountId($b));
        $this->assertNull($this->orderAccountId($elsewhere));
        $this->assertSame((string) $other->getKey(), $this->orderAccountId($taken), 'an order of another account never moves');
        $this->assertSame([[(string) $account->getKey(), 2]], $this->attached);

        // a replayed link: nothing moves, nothing fires
        $late = $this->makeOrder('buyer@example.com');
        $this->postJson('/api/account/verify', $params)->assertStatus(200);
        $this->assertNull($this->orderAccountId($late), 'the replay is not a trigger');
        $this->assertCount(1, $this->attached);
    }

    /** ★ attaching at REGISTRATION (before the mailbox is proven) would hand the orders to whoever types the address */
    public function test_registering_an_address_attaches_nothing_before_it_is_verified(): void
    {
        $order = $this->makeOrder('buyer@example.com');

        $this->postJson('/api/account/register', ['email' => 'buyer@example.com', 'password' => $this->strong, 'password_confirmation' => $this->strong])->assertStatus(202);

        $this->assertNull($this->orderAccountId($order));
        $this->assertSame([], $this->attached);
        $this->assertSame(0, app(GuestOrderAttacher::class)->attach(AccountModel::first()), 'an unverified account attaches nothing');
    }

    public function test_the_reset_that_verifies_an_unverified_email_attaches(): void
    {
        $account = $this->unverifiedAccount('buyer@example.com');
        $order = $this->makeOrder('buyer@example.com');

        $this->postJson('/api/account/password/forgot', ['email' => 'buyer@example.com'])->assertStatus(202);
        $token = $this->linkParams($this->mails(MailTemplates::ACCOUNT_PASSWORD_RESET, 'buyer@example.com')[0])['token'];
        $this->postJson('/api/account/password/reset', ['email' => 'buyer@example.com', 'token' => $token, 'password' => 'a-brand-new-pass-77', 'password_confirmation' => 'a-brand-new-pass-77'])->assertStatus(200);

        $this->assertSame((string) $account->getKey(), $this->orderAccountId($order));
        $this->assertSame([[(string) $account->getKey(), 1]], $this->attached);
    }

    public function test_a_reset_of_an_already_verified_account_is_not_a_trigger(): void
    {
        $this->verifiedAccount('buyer@example.com');
        $order = $this->makeOrder('buyer@example.com');                 // a guest order that appeared after the verification

        $this->postJson('/api/account/password/forgot', ['email' => 'buyer@example.com'])->assertStatus(202);
        $token = $this->linkParams($this->mails(MailTemplates::ACCOUNT_PASSWORD_RESET, 'buyer@example.com')[0])['token'];
        $this->postJson('/api/account/password/reset', ['email' => 'buyer@example.com', 'token' => $token, 'password' => 'a-brand-new-pass-77', 'password_confirmation' => 'a-brand-new-pass-77'])->assertStatus(200);

        $this->assertNull($this->orderAccountId($order));
    }

    /** ★ the email change: the NEW address's guest orders move at the confirm, the OLD address's stay */
    public function test_a_confirmed_email_change_attaches_the_new_addresss_orders_only(): void
    {
        $account = $this->verifiedAccount('old@example.com');
        $oldGuest = $this->makeOrder('old@example.com');
        $newGuest = $this->makeOrder('new@example.com');
        $this->attached = [];

        $this->login('old@example.com')->assertStatus(200);
        $this->postJson('/api/account/email', ['current_password' => $this->strong, 'email' => 'new@example.com'])->assertStatus(202);

        $this->assertNull($this->orderAccountId($newGuest), 'not at request time');

        $params = $this->linkParams($this->mails(MailTemplates::ACCOUNT_EMAIL_CONFIRM, 'new@example.com')[0]);
        $this->postJson('/api/account/email/confirm', $params)->assertStatus(200);

        $this->assertSame((string) $account->getKey(), $this->orderAccountId($newGuest));
        $this->assertNull($this->orderAccountId($oldGuest), 'the old address\'s guest orders stay guest orders');
        $this->assertSame([[(string) $account->getKey(), 1]], $this->attached);
    }

    /** the write fails: the verification still answers 200, the failure is logged by account id and counts (no email), the next trigger / the command retries */
    public function test_a_failing_attach_is_logged_without_the_email_and_never_breaks_the_answer(): void
    {
        $account = $this->unverifiedAccount('buyer@example.com');
        $order = $this->makeOrder('buyer@example.com');
        $this->postJson('/api/account/verify/resend', ['email' => 'buyer@example.com']);
        $params = $this->linkParams($this->mails(MailTemplates::ACCOUNT_VERIFY, 'buyer@example.com')[0]);

        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'update `orders` set `account_id`')) {
                throw new \RuntimeException('the table is locked for buyer@example.com');
            }
        });
        Log::spy();

        $this->postJson('/api/account/verify', $params)->assertStatus(200);

        $this->assertNotNull($account->fresh()->email_verified_at, 'the verification itself is done');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => $message === 'account.orders_attach_failed'
            && $context === ['account_id' => $account->getKey(), 'attached_so_far' => 0, 'exception' => \RuntimeException::class])->once();
        $this->assertSame([], $this->attached);
        // the listener throws AFTER the statement ran, so the row did move; what matters is that the failure is contained and logged without the email
        $this->assertNotNull($order);
    }

    // ---- at placement ---------------------------------------------------------------------------------------------------------

    private function pricedVariation(): string
    {
        self::$variationCounter++;
        $n = self::$variationCounter;
        $product = Product::createSimple("Attach Product {$n}", "ATT-{$n}", "attach-product-{$n}");
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        $this->priceList ??= tap(PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0), fn (PriceList $list) => app(PriceListRepository::class)->save($list));
        app(PriceListItemRepository::class)->save(new PriceListItem(null, $this->priceList->id(), PriceListItemTargetType::VARIATION, $variationId, Price::exclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 0)));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 10));

        return $variationId;
    }

    /** @return array<string, mixed> */
    private function checkoutPayload(string $cartId, string $email): array
    {
        return [
            'cart_id' => $cartId, 'email' => $email,
            'recipient_first_name' => 'Guest', 'recipient_last_name' => 'Buyer', 'phone' => '+359888000000',
            'payment_method' => 'cash_on_delivery', 'delivery_type' => 'street_address',
            'country' => 'BG', 'city' => 'Sofia', 'postal_code' => '1000', 'address_line_1' => 'Vitosha Blvd 1',
            ...$this->shippingPayload(),
        ];
    }

    private function guestCheckout(string $email): string
    {
        $cartId = (string) $this->postJson('/api/cart/lines', ['variation_id' => $this->pricedVariation(), 'quantity' => 1])->assertStatus(201)->json('cart_id');

        return (string) $this->postJson('/api/checkout', $this->checkoutPayload($cartId, $email))->assertStatus(201)->json('order.id');
    }

    /** ★ a guest checkout with a VERIFIED account's email is attached at placement; with an UNVERIFIED account's email it is not */
    public function test_a_guest_order_with_a_verified_accounts_email_is_attached_at_placement_an_unverified_ones_is_not(): void
    {
        $verified = $this->verifiedAccount('verified@example.com');
        $this->unverifiedAccount('pending@example.com');

        $mine = $this->guestCheckout('verified@example.com');
        $pending = $this->guestCheckout('pending@example.com');
        $nobody = $this->guestCheckout('nobody@example.com');

        $this->assertSame((string) $verified->getKey(), $this->orderAccountId($mine));
        $this->assertNull($this->orderAccountId($pending));
        $this->assertNull($this->orderAccountId($nobody));
        $this->assertSame([[(string) $verified->getKey(), 1]], $this->attached);
    }

    // ---- the soft session check (decision 5) ----------------------------------------------------------------------------------

    /**
     * ★ session B is stale (the password was changed in session A): on the public routes it is a GUEST — GET /api/cart is not the account's cart, and
     * POST /api/checkout places a GUEST order (account_id NULL)
     */
    public function test_a_stale_session_continues_as_a_guest_on_the_cart_and_at_checkout(): void
    {
        $account = $this->verifiedAccount('me@example.com');
        $this->login('me@example.com')->assertStatus(200);
        $cartId = (string) $this->postJson('/api/cart/lines', ['variation_id' => $this->pricedVariation(), 'quantity' => 1])->assertStatus(201)->json('cart_id');
        $this->assertNotEmpty($this->getJson('/api/cart')->assertOk()->json('lines'), 'her own cart, logged in');

        // session A changes the password: version 1
        $this->putJson('/api/account/password', ['current_password' => $this->strong, 'password' => 'a-brand-new-pass-77', 'password_confirmation' => 'a-brand-new-pass-77'])->assertStatus(200);

        // session B: still carries version 0 (and the same cart cookie)
        $this->flushSession();
        $this->withSession([\EasyCo\Account\Security\AccountSessionVersion::SESSION_KEY => 0]);
        $this->actingAs($account->fresh(), 'customer');

        $cart = $this->getJson('/api/cart')->assertOk();
        $this->assertSame([], $cart->json('lines'), 'a guest sees no account cart');
        $this->assertNull($cart->json('cart_id'));
        $this->getJson('/api/account/me')->assertStatus(401);

        // checkout from B with the account's cart id: refused as a stranger's cart, never placed as the account
        $before = DB::table('orders')->count();
        $this->postJson('/api/checkout', $this->checkoutPayload($cartId, 'me@example.com'));
        $this->assertSame($before, DB::table('orders')->where('account_id', $account->getKey())->count(), 'nothing is placed as the stale account');
    }

    /**
     * ★ B is stale and goes STRAIGHT to checkout with a guest cart it owns (a cart token in its session; no other route ran first to drop the stale login):
     * only the soft check ON the checkout route makes it a guest order (account_id NULL); without it B would checkout as the stale account
     */
    public function test_a_stale_session_that_checks_out_a_guest_cart_places_a_guest_order(): void
    {
        $account = $this->verifiedAccount('me@example.com');

        // a guest session G fills a cart (nobody is logged in)
        $cartId = (string) $this->postJson('/api/cart/lines', ['variation_id' => $this->pricedVariation(), 'quantity' => 1])->assertStatus(201)->json('cart_id');
        $token = session('cart_token');
        $this->assertNotEmpty($token);

        // session A: logs in and changes the password (version 1)
        $this->flushSession();
        $this->login('me@example.com')->assertStatus(200);
        $this->putJson('/api/account/password', ['current_password' => $this->strong, 'password' => 'a-brand-new-pass-77', 'password_confirmation' => 'a-brand-new-pass-77'])->assertStatus(200);

        // session B: stale (version 0), holds the guest cart token, goes straight to checkout
        $this->flushSession();
        $this->withSession([\EasyCo\Account\Security\AccountSessionVersion::SESSION_KEY => 0, 'cart_token' => $token]);
        $this->actingAs($account->fresh(), 'customer');

        $number = (string) $this->postJson('/api/checkout', $this->checkoutPayload($cartId, 'someone-else@example.com'))->assertStatus(201)->json('order.id');

        $this->assertNull($this->orderAccountId($number), 'a GUEST order: account_id is NULL');
    }

    // ---- the command ----------------------------------------------------------------------------------------------------------

    public function test_the_command_attaches_for_one_account_or_all_is_idempotent_and_prints_counts_only(): void
    {
        $one = $this->verifiedAccount('one@example.com');
        $two = $this->verifiedAccount('two@example.com');
        $this->unverifiedAccount('pending@example.com');
        $o1 = $this->makeOrder('one@example.com');
        $o2 = $this->makeOrder('two@example.com');
        $o3 = $this->makeOrder('pending@example.com');
        $this->attached = [];

        $this->artisan('accounts:attach-guest-orders', ['--account' => (string) $one->getKey()])
            ->expectsOutput('Checked 1 verified account(s); attached 1 guest order(s).')
            ->assertSuccessful();
        $this->assertSame((string) $one->getKey(), $this->orderAccountId($o1));
        $this->assertNull($this->orderAccountId($o2));

        $this->artisan('accounts:attach-guest-orders')
            ->expectsOutput('Checked 2 verified account(s); attached 1 guest order(s).')
            ->assertSuccessful();
        $this->assertSame((string) $two->getKey(), $this->orderAccountId($o2));
        $this->assertNull($this->orderAccountId($o3), 'an unverified account gets nothing');

        $this->artisan('accounts:attach-guest-orders')->expectsOutput('Checked 2 verified account(s); attached 0 guest order(s).');   // idempotent
        $this->assertCount(2, $this->attached, 'the hook only fires when something was attached');

        $this->artisan('accounts:attach-guest-orders', ['--account' => 'x'])->assertFailed();
    }
}
