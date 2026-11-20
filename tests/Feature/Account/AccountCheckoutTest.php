<?php

namespace Tests\Feature\Account;

use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\StaffPanelUser;
use App\Mail\OrderMailRecipients;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\PlacesRegionOrders;
use Tests\Feature\Account\Concerns\DrivesAccountApi;
use Tests\TestCase;

/**
 * ACC-3c: the logged-in checkout. The order email is the account's own (taken on the server); a saved address sent by id is reused and a typed one
 * goes through the dedupe; the address book's cap never fails an order; the optional delivery-mails recipient; the private account API; the
 * checkout page that is the same for everyone; the login from checkout that keeps the cart.
 */
class AccountCheckoutTest extends TestCase
{
    use DrivesAccountApi;
    use PlacesRegionOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driveAccountApi();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    private function me(string $email = 'me@example.com'): AccountModel
    {
        $account = $this->verifiedAccount($email);
        $this->login($email)->assertOk();

        return $account;
    }

    private function rawAddress(AccountModel $account, string $line = 'Vitosha Blvd 1', ?int $marker = null): int
    {
        return (int) DB::table('addresses')->insertGetId([
            'account_id' => $account->getKey(), 'delivery_type' => 'street_address', 'recipient_first_name' => 'Ivan', 'recipient_last_name' => 'Ivanov',
            'phone' => '+359888123456', 'country' => 'BG', 'city' => 'Sofia', 'postal_code' => '1000', 'address_line_1' => $line,
            'default_marker' => $marker, 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00',
        ]);
    }

    private function lastOrder(): object
    {
        return DB::table('orders')->orderByDesc('id')->first();
    }

    private function staff(): void
    {
        $roles = app(RoleRepository::class);
        app(StaffSystemRolesSeeder::class)->run($roles);
        $staff = Staff::create('admin-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), 'Admin Tester', $roles->findSystemRoleByName('Administrator'));
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');
        session()->forget('password_hash_staff');
    }

    // ---- 1: the order email ---------------------------------------------------------------------------------------------------

    public function test_a_logged_in_order_uses_the_account_email_even_when_another_is_posted(): void
    {
        $this->me('me@example.com');

        $this->checkout($this->streetFields('BG'), ['email' => 'someone-else@example.com'])->assertStatus(201)->assertJsonPath('order.email', 'me@example.com');

        $this->assertSame('me@example.com', $this->lastOrder()->email);
        $this->assertNotNull($this->lastOrder()->account_id);
    }

    public function test_a_logged_in_order_needs_no_email_in_the_request(): void
    {
        $this->me('me@example.com');
        $payload = $this->regionPayload($this->regionCart(), $this->streetFields('BG'));
        unset($payload['email']);

        $this->postJson('/api/checkout', $payload)->assertStatus(201)->assertJsonPath('order.email', 'me@example.com');
    }

    public function test_a_request_that_arrives_as_a_guest_without_an_email_is_the_usual_422(): void
    {
        $payload = $this->regionPayload($this->regionCart(), $this->streetFields('BG'));
        unset($payload['email']);

        $this->postJson('/api/checkout', $payload)->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->assertSame(0, DB::table('orders')->count());
    }

    // ---- 2 and 3: the address API of the account --------------------------------------------------------------------------------

    public function test_the_address_endpoint_returns_only_the_current_accounts_addresses_and_a_guest_gets_401(): void
    {
        $other = $this->verifiedAccount('other@example.com');
        $theirs = $this->rawAddress($other, 'Their Street 9');
        $mine = $this->rawAddress($this->me('me@example.com'), 'My Street 1');

        $ids = array_map('intval', array_column($this->getJson('/api/addresses')->assertOk()->json('data'), 'id'));

        $this->assertSame([$mine], $ids);
        $this->assertNotContains($theirs, $ids);

        $this->postJson('/api/account/logout')->assertSuccessful();
        $this->getJson('/api/addresses')->assertStatus(401);
        $this->getJson('/api/account/me')->assertStatus(401);
    }

    public function test_the_account_api_answers_private_no_store(): void
    {
        $this->me();

        foreach (['/api/addresses', '/api/account/me', '/api/account/orders'] as $path) {
            $cache = (string) $this->getJson($path)->assertOk()->headers->get('Cache-Control');

            $this->assertStringContainsString('private', $cache, $path);
            $this->assertStringContainsString('no-store', $cache, $path);
        }
    }

    // ---- 4: the page ------------------------------------------------------------------------------------------------------------

    public function test_the_checkout_page_is_byte_identical_for_a_guest_and_a_logged_in_visitor(): void
    {
        $guest = $this->get('/checkout')->assertOk()->getContent();

        $this->me();
        $member = $this->get('/checkout')->assertOk()->getContent();

        $this->assertSame($guest, $member);
        $this->assertStringNotContainsString('me@example.com', $member);
    }

    // ---- 5 and 6: reuse, new row, the cap ---------------------------------------------------------------------------------------

    public function test_a_saved_address_sent_by_id_is_reused_and_a_changed_one_is_a_new_row_that_leaves_the_old_one_alone(): void
    {
        $account = $this->me();
        $saved = $this->rawAddress($account, 'Rakovski 12');
        $before = (array) DB::table('addresses')->where('id', $saved)->first();

        $this->checkout(['address_id' => (string) $saved])->assertStatus(201);
        $this->assertSame(1, DB::table('addresses')->count(), 'no new row');
        $this->assertSame((string) $saved, (string) $this->lastOrder()->address_id);

        $this->checkout($this->streetFields('BG', ['address_line_1' => 'Rakovski 13']), ['recipient_first_name' => 'Ivan', 'recipient_last_name' => 'Ivanov', 'phone' => '+359888123456'])->assertStatus(201);
        $this->assertSame(2, DB::table('addresses')->count(), 'a changed address is a new record');
        $this->assertSame($before, (array) DB::table('addresses')->where('id', $saved)->first(), 'the old record is never modified from checkout');
        $this->assertNotSame((string) $saved, (string) $this->lastOrder()->address_id);
    }

    public function test_another_accounts_address_id_is_refused_exactly_like_an_unknown_one(): void
    {
        $theirs = $this->rawAddress($this->verifiedAccount('other@example.com'));
        $this->me();

        $foreign = $this->postJson('/api/checkout', $this->regionPayload($this->regionCart(), ['address_id' => (string) $theirs]));
        $unknown = $this->postJson('/api/checkout', $this->regionPayload($this->regionCart(), ['address_id' => '999999']));

        $this->assertSame($unknown->status(), $foreign->status());
        $this->assertSame(str_replace((string) $theirs, 'X', $foreign->getContent()), str_replace('999999', 'X', $unknown->getContent()));
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_a_full_book_never_fails_the_order_it_just_does_not_save_the_address(): void
    {
        $account = $this->me();

        for ($i = 1; $i <= 20; $i++) {
            $this->rawAddress($account, 'Street '.$i);
        }

        $this->checkout($this->streetFields('BG', ['address_line_1' => 'The 21st street']))->assertStatus(201);

        $this->assertSame(20, DB::table('addresses')->where('account_id', $account->getKey())->count());
        $this->assertNull($this->lastOrder()->address_id);
        $this->assertSame('The 21st street', $this->lastOrder()->address_line_1, 'the order carries its own snapshot');
    }

    // ---- a saved pick-up point and the shipping method chosen for it ----------------------------------------------------------

    /** @return array{econt: string, speedy: string, econtLocker: string} method ids: free pick-up methods of two couriers, and an Econt LOCKER method */
    private function courierPickupMethods(): array
    {
        $zone = \EasyCo\Shipping\ShippingZone::create('BG zone (test)', 0, ['BG']);
        app(\EasyCo\Shipping\Contracts\ShippingZoneRepository::class)->save($zone);
        $make = function (string $name, string $courier, ?\EasyCo\Shipping\Enums\ShippingDeliveryType $label) use ($zone): string {
            $method = \EasyCo\Shipping\ShippingMethod::create((string) $zone->id(), $name, \EasyCo\Shipping\Enums\ShippingMethodKind::FREE, 0, true, null, [], null, null, true, \EasyCo\Shipping\Enums\ShippingClassMode::REPLACE, $courier, $label, \EasyCo\Shipping\Enums\ShippingDestinationScope::PICKUP);
            app(\EasyCo\Shipping\Contracts\ShippingMethodRepository::class)->save($method);

            return (string) $method->id();
        };

        return ['econt' => $make('Econt point', 'Econt', null), 'speedy' => $make('Speedy point', 'Speedy', null), 'econtLocker' => $make('Econt locker', 'Econt', \EasyCo\Shipping\Enums\ShippingDeliveryType::LOCKER)];
    }

    private function savedOffice(AccountModel $account): int
    {
        return (int) DB::table('addresses')->insertGetId([
            'account_id' => $account->getKey(), 'delivery_type' => 'pickup_point', 'recipient_first_name' => 'Ivan', 'recipient_last_name' => 'Ivanov', 'phone' => '+359888123456',
            'country' => 'BG', 'carrier_code' => 'Econt', 'pickup_point_kind' => 'office', 'settlement' => 'Sofia', 'pickup_point_address' => 'Econt Center 1',
            'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00',
        ]);
    }

    private function checkoutWithMethod(int $addressId, string $methodId): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/checkout', $this->regionPayload($this->regionCart(), ['address_id' => (string) $addressId], ['shipping_method_id' => $methodId]));
    }

    public function test_a_saved_pickup_point_is_refused_with_a_method_of_another_courier_with_the_same_reason_as_a_mismatched_typed_point(): void
    {
        $methods = $this->courierPickupMethods();
        $id = $this->savedOffice($this->me());

        $this->checkoutWithMethod($id, $methods['speedy'])->assertStatus(422)->assertJsonPath('reason', 'shipping_pickup_mismatch');
        $this->assertSame(0, DB::table('orders')->count(), 'nothing is written');

        $this->checkoutWithMethod($id, $methods['econt'])->assertStatus(201);
        $this->assertSame((string) $id, (string) $this->lastOrder()->address_id);
    }

    public function test_a_saved_office_is_refused_with_a_locker_method_of_the_same_courier(): void
    {
        $methods = $this->courierPickupMethods();
        $id = $this->savedOffice($this->me());

        $this->checkoutWithMethod($id, $methods['econtLocker'])->assertStatus(422)->assertJsonPath('reason', 'shipping_pickup_mismatch');
        $this->assertSame(0, DB::table('orders')->count());
    }

    // ---- 7: the recipient email -------------------------------------------------------------------------------------------------

    public function test_the_recipient_email_is_stored_trimmed_and_lower_cased(): void
    {
        $this->checkout($this->streetFields('BG'), ['recipient_email' => '  Mum@Example.COM '])->assertStatus(201);

        $this->assertSame('mum@example.com', $this->lastOrder()->recipient_email);
    }

    public function test_a_recipient_equal_to_the_buyer_in_another_case_is_stored_as_null(): void
    {
        $this->checkout($this->streetFields('BG'), ['email' => 'guest@example.com', 'recipient_email' => 'GUEST@Example.com'])->assertStatus(201);
        $this->assertNull($this->lastOrder()->recipient_email);

        $this->me('me@example.com');
        $this->checkout($this->streetFields('BG'), ['recipient_email' => 'Me@EXAMPLE.com'])->assertStatus(201);
        $this->assertNull($this->lastOrder()->recipient_email);

        $this->checkout($this->streetFields('BG'), ['recipient_email' => ''])->assertStatus(201);
        $this->assertNull($this->lastOrder()->recipient_email);
    }

    public function test_an_invalid_or_too_long_recipient_email_is_a_422_and_writes_nothing(): void
    {
        foreach (['not-an-email', 'a b@example.com', str_repeat('a', 250).'@x.io', "ok@example.com\r\nBcc: x@example.com", ['a@b.io']] as $bad) {
            $this->checkout($this->streetFields('BG'), ['recipient_email' => $bad])->assertStatus(422)->assertJsonValidationErrors(['recipient_email']);
        }

        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_the_recipient_is_never_written_to_the_address_book(): void
    {
        $this->me();

        $this->checkout($this->streetFields('BG'), ['recipient_email' => 'mum@example.com'])->assertStatus(201);

        $this->assertSame(1, DB::table('addresses')->count());
        $this->assertStringNotContainsString('mum@example.com', json_encode(DB::table('addresses')->get(), JSON_THROW_ON_ERROR));
    }

    // ---- 8: who gets which mail -------------------------------------------------------------------------------------------------

    public function test_delivery_mails_go_to_the_buyer_and_the_recipient_and_every_other_mail_to_the_buyer_only(): void
    {
        $this->checkout($this->streetFields('BG'), ['email' => 'buyer@example.com', 'recipient_email' => 'mum@example.com'])->assertStatus(201);
        $order = app(OrderRepository::class)->findById((string) $this->lastOrder()->id);

        foreach (OrderMailRecipients::DELIVERY_MAILS as $key) {
            $this->assertSame(['buyer@example.com', 'mum@example.com'], OrderMailRecipients::for($key, $order), $key);
        }

        $this->assertSame(['buyer@example.com'], OrderMailRecipients::for('order.confirmation', $order));
        $this->assertSame(['buyer@example.com'], OrderMailRecipients::for('account.verify', $order));
    }

    public function test_an_order_without_a_recipient_sends_a_delivery_mail_to_the_buyer_once(): void
    {
        $this->checkout($this->streetFields('BG'), ['email' => 'buyer@example.com'])->assertStatus(201);
        $order = app(OrderRepository::class)->findById((string) $this->lastOrder()->id);

        $this->assertSame(['buyer@example.com'], OrderMailRecipients::for('order.shipped', $order));
    }

    // ---- 9: login from checkout -------------------------------------------------------------------------------------------------

    public function test_logging_in_from_checkout_keeps_the_cart_the_guest_filled(): void
    {
        $this->verifiedAccount('me@example.com');
        $guestCart = $this->regionCart();
        $guestVariation = (string) DB::table('cart_lines')->where('cart_id', $guestCart)->value('variation_id');

        $this->login('me@example.com')->assertOk();

        $cart = $this->getJson('/api/cart')->assertOk();
        $this->assertSame([$guestVariation], array_map('strval', array_column($cart->json('lines'), 'variation_id')), 'the guest line is in the cart after the login');

        // the page reloads the cart (a new id after the merge) and the order is placed on it
        $this->postJson('/api/checkout', $this->regionPayload((string) $cart->json('cart_id'), $this->streetFields('BG')))->assertStatus(201)->assertJsonPath('order.email', 'me@example.com');
    }

    // ---- 10: who sees the recipient ---------------------------------------------------------------------------------------------

    public function test_the_recipient_is_in_the_account_order_the_received_page_and_the_admin_view_and_in_no_public_page(): void
    {
        $this->me('me@example.com');
        $response = $this->checkout($this->streetFields('BG'), ['recipient_email' => 'Mum@Example.com'])->assertStatus(201);
        $orderId = (string) $this->lastOrder()->id;

        $this->getJson('/api/account/orders/'.$orderId)->assertOk()->assertJsonPath('data.delivery_notice_email', 'mum@example.com');
        $this->get('/account/orders/'.$orderId)->assertOk()->assertSee('mum@example.com');
        $this->get($response->json('confirmation_url'))->assertOk()->assertSee('Delivery notifications: mum@example.com', false);

        $this->postJson('/api/account/logout')->assertSuccessful();

        foreach (['/', '/checkout', '/cart'] as $public) {
            $this->get($public)->assertDontSee('mum@example.com');
        }

        $this->staff();
        app()->setLocale('en');
        $html = Livewire::test(ViewOrder::class, ['record' => $orderId])->html();
        $this->assertStringContainsString('mum@example.com', $html);
        $this->assertStringContainsString('Delivery notifications (email)', $html);
    }

    public function test_an_order_without_a_recipient_shows_no_line_for_it(): void
    {
        $this->me();
        $response = $this->checkout($this->streetFields('BG'))->assertStatus(201);

        $this->get($response->json('confirmation_url'))->assertOk()->assertDontSee('Delivery notifications');
    }
}
