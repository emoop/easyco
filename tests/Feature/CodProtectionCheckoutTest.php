<?php

namespace Tests\Feature;

use App\Enums\OrderBlockType;
use App\Services\OrderBlockService;
use EasyCo\Payment\Registry\PaymentMethodDescriptor;
use EasyCo\Payment\Registry\PaymentMethodRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * Cash-on-delivery protection, stage C2-B (cod-protection-design.md section 4): at placement, after the method's own availability check and
 * before anything is written, a BLOCKABLE method (cash on delivery) is refused with 422 `payment_method_blocked` when an ACTIVE block matches the
 * request IP, the order's phone key or its email key. Bank transfer is never refused; an expired or revoked block refuses nothing; the answer is
 * neutral (it never names the identifier or which kind matched); a replay of an order placed before the block returns that order.
 */
class CodProtectionCheckoutTest extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;

    private const PHONE = '0888 123 456';

    private const EMAIL = 'Fake.Orderer@Example.com';

    private const IP = '203.0.113.50';

    protected function setUp(): void
    {
        parent::setUp();

        // PH-1: a phone typed by hand is read with the STORE's country (a configured shop always has one).
        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set(\App\Settings\StoreCountry::KEY, 'BG');

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->setUpRegionOrders();
    }

    private function blocks(): OrderBlockService
    {
        return app(OrderBlockService::class);
    }

    /** The same customer every time: the phone, the email and the address of this class's constants. */
    private function order(string $method = 'cash_on_delivery', array $extra = [], string $ip = self::IP): TestResponse
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);

        return $this->checkout($this->streetFields('BG'), array_merge(['payment_method' => $method, 'phone' => self::PHONE, 'email' => self::EMAIL], $extra));
    }

    private function assertNothingWasWritten(): void
    {
        foreach (['orders', 'order_attributions', 'order_events', 'payments', 'operational_sales_transactions', 'operational_sales_sale_lines'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} is empty: a refusal writes nothing");
        }
    }

    // ---- a block refuses cash on delivery ---------------------------------------------------------------------------------------------

    /** @return array<string, array{0: OrderBlockType, 1: string}> */
    public static function blockedIdentifiers(): array
    {
        return [
            'the phone, typed another way' => [OrderBlockType::PHONE, '+359 888 123 456'],
            'the email, in another case' => [OrderBlockType::EMAIL, 'fake.orderer@example.COM'],
            'the IP address' => [OrderBlockType::IP, self::IP],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockedIdentifiers')]
    public function test_a_blocked_identifier_refuses_cash_on_delivery_and_writes_nothing(OrderBlockType $type, string $value): void
    {
        $this->blocks()->block($type, $value, 180);

        $response = $this->order()->assertStatus(422);

        $response->assertJsonPath('reason', 'payment_method_blocked');
        $this->assertSame(__('checkout.payment_method_blocked'), $response->json('message'));
        $this->assertNothingWasWritten();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockedIdentifiers')]
    public function test_the_same_customer_can_still_pay_by_bank_transfer(OrderBlockType $type, string $value): void
    {
        $this->blocks()->block($type, $value, null);

        $this->order('bank_transfer')->assertStatus(201)->assertJsonPath('already_placed', false);

        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_a_customer_who_matches_no_block_orders_cash_on_delivery_as_before(): void
    {
        $this->blocks()->block(OrderBlockType::PHONE, '0877 000 111', null);
        $this->blocks()->block(OrderBlockType::EMAIL, 'someone.else@example.com', null);
        $this->blocks()->block(OrderBlockType::IP, '198.51.100.1', null);

        $this->order()->assertStatus(201);
    }

    public function test_an_ipv6_address_is_blocked_by_its_slash_64_prefix_and_ipv4_exactly(): void
    {
        $this->blocks()->block(OrderBlockType::IP, '2001:db8:1:2:aaaa:bbbb:cccc:dddd', 30);

        $this->order(ip: '2001:db8:1:2:1111:2222:3333:4444')->assertStatus(422)->assertJsonPath('reason', 'payment_method_blocked');
        $this->order(ip: '2001:db8:1:3:1111:2222:3333:4444')->assertStatus(201);

        $this->blocks()->block(OrderBlockType::IP, '203.0.113.7', 30);
        $this->order(ip: '203.0.113.8')->assertStatus(201);
        $this->order(ip: '203.0.113.7')->assertStatus(422);
    }

    // ---- ended blocks refuse nothing ---------------------------------------------------------------------------------------------------

    public function test_an_expired_block_refuses_nothing(): void
    {
        // Blocks that ended yesterday (the placement instant is the request's own clock, so the rows are written already expired).
        foreach ([['phone', '888123456', self::PHONE], ['email', 'fake.orderer@example.com', self::EMAIL], ['ip', self::IP, self::IP]] as [$type, $key, $display]) {
            \App\Models\OrderBlockModel::query()->create(['type' => $type, 'value_key' => $key, 'value_display' => $display, 'expires_at' => Carbon::now()->subDay()]);
        }

        $this->order()->assertStatus(201);
    }

    public function test_a_revoked_block_refuses_nothing_and_a_permanent_one_always_does(): void
    {
        $block = $this->blocks()->block(OrderBlockType::PHONE, self::PHONE, null);
        $this->order()->assertStatus(422);

        $this->blocks()->revoke($block);

        $this->order()->assertStatus(201);

        $this->blocks()->block(OrderBlockType::EMAIL, self::EMAIL, null);
        $this->order(extra: ['phone' => '0877 999 888'])->assertStatus(422);
    }

    // ---- the answer is neutral ------------------------------------------------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('blockedIdentifiers')]
    public function test_the_refusal_never_names_the_identifier_or_which_kind_matched(OrderBlockType $type, string $value): void
    {
        $this->blocks()->block($type, $value, 180, 'a staff note that must stay inside');

        $response = $this->order()->assertStatus(422);
        $body = $response->getContent();

        $this->assertSame(['message', 'reason'], array_keys($response->json()), 'nothing else is in the answer');
        foreach ([self::PHONE, '888123456', self::EMAIL, strtolower(self::EMAIL), self::IP, 'staff note'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        foreach (['phone', 'email', 'ip ', 'blocked', 'block'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, (string) $response->json('message'));
        }

        // The Bulgarian sentence is the owner's, and just as neutral.
        $this->assertSame('Този начин на плащане не е наличен за тази поръчка. Моля, изберете друг.', __('checkout.payment_method_blocked', [], 'bg'));
    }

    public function test_the_refused_cart_is_not_claimed_and_can_be_ordered_by_another_method(): void
    {
        $this->blocks()->block(OrderBlockType::PHONE, self::PHONE, null);
        $payload = $this->regionPayload($this->regionCart(), $this->streetFields('BG'), ['payment_method' => 'cash_on_delivery', 'phone' => self::PHONE, 'email' => self::EMAIL]);

        $this->postJson('/api/checkout', $payload)->assertStatus(422)->assertJsonPath('reason', 'payment_method_blocked');
        $this->assertNothingWasWritten();

        $this->postJson('/api/checkout', array_merge($payload, ['payment_method' => 'bank_transfer']))->assertStatus(201)->assertJsonPath('already_placed', false);
        $this->assertSame(1, DB::table('orders')->count(), 'the same cart, ordered by the method that was left');
    }

    public function test_a_replay_of_an_order_placed_before_the_block_returns_that_order(): void
    {
        $payload = $this->regionPayload($this->regionCart(), $this->streetFields('BG'), ['payment_method' => 'cash_on_delivery', 'phone' => self::PHONE, 'email' => self::EMAIL]);
        $first = $this->postJson('/api/checkout', $payload)->assertStatus(201);

        $this->blocks()->block(OrderBlockType::PHONE, self::PHONE, null);
        $this->blocks()->block(OrderBlockType::EMAIL, self::EMAIL, null);

        $replay = $this->postJson('/api/checkout', $payload)->assertStatus(201);

        $replay->assertJsonPath('already_placed', true);
        $this->assertSame($first->json('order.id'), $replay->json('order.id'));
        $this->assertSame(1, DB::table('orders')->count());
    }

    // ---- the flag and the cost ---------------------------------------------------------------------------------------------------------------

    public function test_only_cash_on_delivery_is_blockable_and_an_extension_must_opt_in(): void
    {
        $registry = app(PaymentMethodRegistry::class);

        $this->assertTrue($registry->find('cash_on_delivery')->blockable);
        $this->assertFalse($registry->find('bank_transfer')->blockable);
        $this->assertFalse((new PaymentMethodDescriptor(code: 'card', label: fn (string $l): string => 'Card'))->blockable, 'an extension decides for its own method; the default is not blockable');
    }

    public function test_an_extension_method_that_is_blockable_is_refused_like_cash_on_delivery(): void
    {
        app(PaymentMethodRegistry::class)->register(new PaymentMethodDescriptor(code: 'pay_later', label: fn (string $l): string => 'Pay later', sortOrder: 30, blockable: true));
        $this->app->bind('payment.adapter.pay_later', \EasyCo\Payment\Adapters\CashOnDeliveryPaymentMethodAdapter::class);
        $this->blocks()->block(OrderBlockType::PHONE, self::PHONE, null);

        $this->order('pay_later')->assertStatus(422)->assertJsonPath('reason', 'payment_method_blocked');
    }

    public function test_a_cash_on_delivery_placement_asks_one_lookup_and_a_bank_transfer_asks_none(): void
    {
        $lookups = 0;
        DB::listen(function ($query) use (&$lookups): void {
            $lookups += str_contains($query->sql, 'from `order_blocks`') ? 1 : 0;
        });

        $this->order('bank_transfer')->assertStatus(201);
        $this->assertSame(0, $lookups, 'a method that is not blockable asks nothing');

        $this->blocks()->block(OrderBlockType::PHONE, '0877 000 111', null);
        $lookups = 0;
        $this->order('cash_on_delivery')->assertStatus(201);
        $this->assertSame(1, $lookups, 'ONE lookup of the three keys together');
    }
}
