<?php

namespace Tests\Feature\Security;

use App\Security\Honeypot;
use App\Security\SecurityMode;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\ProvidesCheckoutShipping;
use Tests\Feature\Storefront\Cache\BuildsCacheShop;
use Tests\TestCase;

/** P8d: SECURITY_MODE (log never blocks, block blocks) and the honeypot on the checkout submit and the registration. */
class HoneypotAndModeTest extends TestCase
{
    use BuildsCacheShop;
    use ProvidesCheckoutShipping;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop();
        $this->withHeader('Referer', 'http://localhost/');
    }

    /** @return array<string, mixed> */
    private function checkoutBody(array $extra = []): array
    {
        $shipping = $this->shippingPayload();
        $cartId = (string) $this->postJson('/api/cart/lines', ['variation_id' => '101', 'quantity' => 1])->assertStatus(201)->json('cart_id');

        return array_merge([
            'cart_id' => $cartId,
            'email' => 'guest@example.com',
            'recipient_first_name' => 'Guest', 'recipient_last_name' => 'Buyer',
            'phone' => '+359888000000',
            'payment_method' => 'cash_on_delivery',
            'delivery_type' => 'street_address',
            'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'Vitosha Blvd 1',
        ], $shipping, $extra);
    }

    /** @return array<string, string> */
    private function registrationBody(array $extra = []): array
    {
        return array_merge(['email' => 'new@example.com', 'password' => 'long-enough-1', 'password_confirmation' => 'long-enough-1'], $extra);
    }

    // ---- the mode ---------------------------------------------------------------------------------------------------------------------

    public function test_the_mode_helper_blocks_only_in_block_mode_and_a_typo_means_log(): void
    {
        Log::spy();
        $logged = 0;

        foreach (['block' => true, 'log' => false, 'Block' => false, 'on' => false, '' => false] as $mode => $blocks) {
            config(['security.mode' => (string) $mode]);

            $this->assertSame($blocks, SecurityMode::decide('test_rule', ['k' => 'v']), "mode «{$mode}»");
            $blocks || $logged++;
        }

        Log::shouldHaveReceived('warning')->times($logged);
    }

    /** ★ every rule of the package logs and does NOT block in `log`, blocks in `block` */
    public function test_a_rule_that_ignores_the_mode_is_caught_here(): void
    {
        $rules = [
            'honeypot checkout' => function (): bool {
                $this->postJson('/api/checkout', $this->checkoutBody(['ec_hp' => 'bot']))->assertStatus(201);

                return true;
            },
        ];

        config(['security.mode' => 'log']);
        foreach ($rules as $name => $placesTheOrder) {
            $before = OrderModel::query()->count();
            $placesTheOrder();
            $this->assertSame($before + 1, OrderModel::query()->count(), "{$name}: log mode must not block");
        }
    }

    // ---- the honeypot -----------------------------------------------------------------------------------------------------------------

    public function test_an_empty_honeypot_changes_nothing_for_the_checkout_and_the_registration(): void
    {
        config(['security.mode' => 'block']);

        $this->postJson('/api/checkout', $this->checkoutBody(['ec_hp' => '']))->assertStatus(201);
        $this->postJson('/api/account/register', $this->registrationBody(['ec_hp' => '']))->assertStatus(201);

        $this->assertSame(1, OrderModel::query()->count());
        $this->assertSame(1, AccountModel::query()->count());
    }

    public function test_a_filled_honeypot_in_log_mode_logs_once_and_the_order_and_the_account_are_made(): void
    {
        config(['security.mode' => 'log']);
        Log::spy();

        $this->postJson('/api/checkout', $this->checkoutBody(['ec_hp' => 'http://spam']))->assertStatus(201);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m): bool => str_contains($m, 'honeypot_checkout'))->once();

        $this->postJson('/api/account/register', $this->registrationBody(['ec_hp' => 'x']))->assertStatus(201);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m): bool => str_contains($m, 'honeypot_registration'))->once();

        $this->assertSame(1, OrderModel::query()->count());
        $this->assertSame(1, AccountModel::query()->count());
    }

    /** ★ block mode: the form's own generic validation error, nothing written */
    public function test_a_filled_honeypot_in_block_mode_is_a_generic_validation_error_and_writes_nothing(): void
    {
        config(['security.mode' => 'block']);

        $checkout = $this->postJson('/api/checkout', $this->checkoutBody(['ec_hp' => 'bot']));
        $checkout->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->assertStringNotContainsStringIgnoringCase('honey', (string) $checkout->getContent());
        $this->assertStringNotContainsString('ec_hp', (string) $checkout->getContent());

        $this->postJson('/api/account/register', $this->registrationBody(['ec_hp' => 'bot']))->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->assertSame(0, OrderModel::query()->count());
        $this->assertSame(0, AccountModel::query()->count());
    }

    public function test_the_field_is_in_the_checkout_html_and_hidden_without_a_style_attribute(): void
    {
        $html = $this->get('/checkout')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~<div class="sf-hpot" aria-hidden="true"><input type="text" name="ec_hp" value="" tabindex="-1" autocomplete="off"[^>]*></div>~', $html);
        $this->assertDoesNotMatchRegularExpression('~<div class="sf-hpot"[^>]*style=~', $html);
        $this->assertStringNotContainsString('<label for="ec_hp"', $html);
        $this->assertSame('ec_hp', Honeypot::field());
    }

    public function test_the_field_class_is_off_screen_and_not_display_none(): void
    {
        $css = (string) file_get_contents(resource_path('css/storefront.css'));
        preg_match('~\.sf-hpot \{([^}]*)\}~', $css, $m);

        $this->assertNotEmpty($m, '.sf-hpot is in the storefront stylesheet');
        $this->assertStringContainsString('left: -10000px', $m[1]);
        $this->assertStringNotContainsString('display: none', $m[1]);
    }
}
