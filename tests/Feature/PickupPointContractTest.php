<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\StaffPanelUser;
use App\Services\OrderPlacementSnapshotWriter;
use DateTimeImmutable;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Order;
use EasyCo\Order\Persistence\Eloquent\OrderPlacementSnapshotModel;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingDeliveryType;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingMethod;
use EasyCo\Shipping\ShippingZone;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Shipping stage 4f (shipping-domain-design.md section 9.1.6): the pickup point's DISPLAY SNAPSHOT — pickup_point_name and
 * pickup_point_address — on the saved address, the order and the placement snapshot. Display text only: the reference stays the
 * identifier and nothing is verified against a courier.
 */
class PickupPointContractTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = 'Econt office Center';

    private const ADDRESS = 'Vitosha Blvd 100, Sofia';

    private static int $counter = 0;

    private ?string $lockerId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost/');
    }

    // --- fixtures -------------------------------------------------------------------------------------------------

    private function lockerMethod(): string
    {
        $zone = ShippingZone::create('Zone '.(++self::$counter), 0, ['BG']);
        app(ShippingZoneRepository::class)->save($zone);
        $method = ShippingMethod::create((string) $zone->id(), 'To locker', ShippingMethodKind::FLAT, 0, true, 300, [], null, null, true, ShippingClassMode::REPLACE, 'Econt', ShippingDeliveryType::LOCKER);
        app(ShippingMethodRepository::class)->save($method);

        return $this->lockerId = (string) $method->id();
    }

    private function fillCart(): string
    {
        self::$counter++;
        $n = self::$counter;
        $product = Product::createSimple("Pickup Product {$n}", "PU-{$n}", "pickup-product-{$n}");
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();
        $list = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
        app(PriceListRepository::class)->save($list);
        app(PriceListItemRepository::class)->save(new PriceListItem(null, $list->id(), PriceListItemTargetType::VARIATION, $variationId, Price::exclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 0)));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 50));

        return (string) $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => 1])->assertStatus(201)->json('cart_id');
    }

    /** @return array<string, mixed> the real quote's choice of the locker method for a pickup point in Sofia */
    private function lockerChoice(): array
    {
        $methods = $this->postJson('/api/shipping/quote', ['delivery_type' => 'pickup_point', 'country' => 'BG', 'settlement' => 'Sofia'])->assertOk()->json('methods');
        $locker = collect($methods)->firstWhere('id', $this->lockerId);

        return ['shipping_method_id' => $locker['id'], 'quote_handle' => $locker['handle'], 'expected_shipping_minor' => $locker['price']['minor']];
    }

    /** @return array<string, mixed> */
    private function pickupFields(array $override = []): array
    {
        return array_merge([
            'delivery_type' => 'pickup_point', 'country' => 'BG', 'carrier_code' => 'econt', 'pickup_point_reference' => 'office-1234',
            'settlement' => 'Sofia', 'pickup_point_name' => self::NAME, 'pickup_point_address' => self::ADDRESS,
        ], $override);
    }

    /** @return array<string, mixed> */
    private function checkoutPayload(string $cartId, array $address, array $override = []): array
    {
        return array_merge([
            'cart_id' => $cartId, 'email' => 'guest@example.com', 'recipient_name' => 'Guest Buyer', 'phone' => '+359888000000',
            'payment_method' => 'cash_on_delivery',
        ], $address, $this->lockerChoice(), $override);
    }

    private function customer(): AccountModel
    {
        $account = Account::register('buyer-'.Str::uuid().'@example.com', 'hashed-password');
        app(AccountRepository::class)->save($account);
        $model = AccountModel::findOrFail($account->id());
        $this->actingAs($model, 'customer');

        return $model;
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

    /** @return array<string, int> */
    private function rows(): array
    {
        return ['orders' => DB::table('orders')->count(), 'addresses' => DB::table('addresses')->count(), 'snapshots' => DB::table('order_placement_snapshots')->count(), 'payments' => DB::table('payments')->count()];
    }

    // --- the round trip ---------------------------------------------------------------------------------------------------

    public function test_a_pickup_checkout_stores_the_display_snapshot_on_the_address_the_order_and_the_snapshot_and_returns_and_replays_it(): void
    {
        $this->lockerMethod();
        $cartId = $this->fillCart();
        $payload = $this->checkoutPayload($cartId, $this->pickupFields(['pickup_point_name' => '  '.self::NAME.'  ']));

        $first = $this->postJson('/api/checkout', $payload)->assertStatus(201);

        $first->assertJsonPath('order.pickup_point_name', self::NAME)
            ->assertJsonPath('order.pickup_point_address', self::ADDRESS)
            ->assertJsonPath('order.pickup_point_reference', 'office-1234');
        $order = DB::table('orders')->sole();
        $snapshot = (array) DB::table('order_placement_snapshots')->sole();
        $address = DB::table('addresses')->sole();
        $this->assertSame([self::NAME, self::ADDRESS], [$order->pickup_point_name, $order->pickup_point_address], 'trimmed on the order');
        $this->assertSame([self::NAME, self::ADDRESS], [$address->pickup_point_name, $address->pickup_point_address]);
        $this->assertSame([self::NAME, self::ADDRESS], [$snapshot['pickup_point_name'], $snapshot['pickup_point_address']]);

        $reloaded = app(OrderRepository::class)->findById((string) $order->id);
        $expected = app(OrderPlacementSnapshotWriter::class)->rowFor($reloaded, new DateTimeImmutable('2000-01-01'));
        foreach ($expected as $column => $value) {
            if ($column !== 'created_at') {
                $this->assertEquals($value, $snapshot[$column], "snapshot column {$column} equals the order");
            }
        }

        $replay = $this->postJson('/api/checkout', $payload)->assertStatus(201)->assertJsonPath('already_placed', true);
        $this->assertSame($first->json('order.pickup_point_name'), $replay->json('order.pickup_point_name'));
        $this->assertSame($first->json('order.pickup_point_address'), $replay->json('order.pickup_point_address'));
    }

    public function test_a_street_order_carries_null_display_fields(): void
    {
        $zone = ShippingZone::create('Zone S', 0, ['BG']);
        app(ShippingZoneRepository::class)->save($zone);
        $method = ShippingMethod::create((string) $zone->id(), 'Flat', ShippingMethodKind::FLAT, 0, true, 500);
        app(ShippingMethodRepository::class)->save($method);
        $cartId = $this->fillCart();
        $quoted = collect($this->postJson('/api/shipping/quote', ['delivery_type' => 'street_address', 'country' => 'BG', 'settlement' => 'Sofia'])->assertOk()->json('methods'))->firstWhere('id', (string) $method->id());

        $response = $this->postJson('/api/checkout', [
            'cart_id' => $cartId, 'email' => 'g@example.com', 'recipient_name' => 'G B', 'phone' => '+359888000000', 'payment_method' => 'cash_on_delivery',
            'delivery_type' => 'street_address', 'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'Vitosha 1',
            'shipping_method_id' => $quoted['id'], 'quote_handle' => $quoted['handle'], 'expected_shipping_minor' => 500,
        ])->assertStatus(201);

        $response->assertJsonPath('order.pickup_point_name', null)->assertJsonPath('order.pickup_point_address', null);
    }

    public function test_a_saved_pickup_address_created_through_the_api_checks_out_with_its_display_text(): void
    {
        $this->lockerMethod();
        $this->customer();
        $addressId = $this->postJson('/api/addresses', array_merge($this->pickupFields(), ['recipient_name' => 'Ivan Ivanov', 'phone' => '+359888111222']))
            ->assertStatus(201)->assertJsonPath('pickup_point_name', self::NAME)->assertJsonPath('pickup_point_address', self::ADDRESS)->json('id');
        $cartId = $this->fillCart();

        $this->postJson('/api/checkout', $this->checkoutPayload($cartId, ['address_id' => $addressId], ['email' => 'buyer@example.com']))->assertStatus(201)
            ->assertJsonPath('order.pickup_point_name', self::NAME)
            ->assertJsonPath('order.pickup_point_address', self::ADDRESS);
        $this->assertSame(self::NAME, DB::table('orders')->sole()->pickup_point_name);
    }

    public function test_renaming_or_deleting_anything_about_the_courier_cannot_change_a_stored_order(): void
    {
        $this->lockerMethod();
        $cartId = $this->fillCart();
        $payload = $this->checkoutPayload($cartId, $this->pickupFields());
        $first = $this->postJson('/api/checkout', $payload)->assertStatus(201);
        $this->staff();

        $everything = fn (): array => [
            (array) DB::table('orders')->sole(),
            (array) DB::table('order_placement_snapshots')->sole(),
            $first->json('order.pickup_point_name'),
            $this->postJson('/api/checkout', $payload)->json('order'),
        ];
        $before = $everything();

        DB::table('shipping_methods')->update(['name' => 'Renamed', 'courier' => 'Speedy']);
        DB::table('shipping_methods')->delete();
        DB::table('shipping_zones')->delete();
        $after = $everything();

        $this->assertSame($before, $after);
        $this->assertSame(self::NAME, $after[0]['pickup_point_name']);
    }

    // --- the requests ---------------------------------------------------------------------------------------------------------

    /** @return array<string, array{array<string, mixed>, string}> override of the pickup fields => the field that must be refused */
    public static function badRequests(): array
    {
        return [
            'missing name' => [['pickup_point_name' => null], 'pickup_point_name'],
            'missing address' => [['pickup_point_address' => null], 'pickup_point_address'],
            'blank name' => [['pickup_point_name' => '   '], 'pickup_point_name'],
            'name too long' => [['pickup_point_name' => str_repeat('я', 256)], 'pickup_point_name'],
            'address too long' => [['pickup_point_address' => str_repeat('я', 256)], 'pickup_point_address'],
            '10,000 characters' => [['pickup_point_address' => str_repeat('Z', 10000)], 'pickup_point_address'],
            'array name' => [['pickup_point_name' => ['x']], 'pickup_point_name'],
            'object address' => [['pickup_point_address' => ['a' => 'b']], 'pickup_point_address'],
            'newline' => [['pickup_point_name' => "Office\nCenter"], 'pickup_point_name'],
            'NUL byte' => [['pickup_point_address' => "Vitosha\0 100"], 'pickup_point_address'],
            'tab' => [['pickup_point_name' => "Office\tCenter"], 'pickup_point_name'],
            'bidi override' => [['pickup_point_address' => "Vitosha\u{202E} 100"], 'pickup_point_address'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badRequests')]
    public function test_a_bad_display_value_at_checkout_is_a_422_field_error_without_echo_and_writes_nothing(array $override, string $field): void
    {
        $this->lockerMethod();
        $cartId = $this->fillCart();

        $response = $this->postJson('/api/checkout', $this->checkoutPayload($cartId, $this->pickupFields($override)))->assertStatus(422)->assertJsonValidationErrors([$field]);

        $body = (string) $response->getContent();
        foreach (['ZZZZZZZZZZ', 'яяяяяяяя', 'Vitosha'] as $typed) {
            $this->assertStringNotContainsString($typed, $body);
        }
        $this->assertSame(['orders' => 0, 'addresses' => 0, 'snapshots' => 0, 'payments' => 0], $this->rows());
        $this->assertNull(DB::table('carts')->where('id', $cartId)->value('order_id'));
    }

    public function test_the_display_fields_are_refused_for_a_street_address_at_checkout_and_in_the_address_api(): void
    {
        $this->lockerMethod();
        $cartId = $this->fillCart();
        $street = ['delivery_type' => 'street_address', 'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'Vitosha 1'];

        foreach (['pickup_point_name', 'pickup_point_address'] as $field) {
            $this->postJson('/api/checkout', $this->checkoutPayload($cartId, array_merge($street, [$field => 'Something'])))->assertStatus(422)->assertJsonValidationErrors([$field]);
        }
        $this->assertSame(0, DB::table('orders')->count());

        $this->customer();
        foreach (['pickup_point_name', 'pickup_point_address'] as $field) {
            $this->postJson('/api/addresses', array_merge($street, ['recipient_name' => 'I I', 'phone' => '+359888111222', $field => 'Something']))->assertStatus(422)->assertJsonValidationErrors([$field]);
        }
        $this->assertSame(0, DB::table('addresses')->count());
    }

    public function test_the_address_api_needs_both_for_a_pickup_point_on_create_and_on_update(): void
    {
        $this->customer();
        $base = array_merge($this->pickupFields(), ['recipient_name' => 'Ivan Ivanov', 'phone' => '+359888111222']);

        foreach (['pickup_point_name', 'pickup_point_address'] as $field) {
            $without = $base;
            unset($without[$field]);
            $this->postJson('/api/addresses', $without)->assertStatus(422)->assertJsonValidationErrors([$field]);
        }
        $this->assertSame(0, DB::table('addresses')->count());

        $id = $this->postJson('/api/addresses', $base)->assertStatus(201)->json('id');
        $without = $base;
        unset($without['pickup_point_name']);
        $this->putJson("/api/addresses/{$id}", $without)->assertStatus(422)->assertJsonValidationErrors(['pickup_point_name']);
        $this->assertSame(self::NAME, DB::table('addresses')->where('id', $id)->value('pickup_point_name'), 'the stored address is untouched');

        $this->putJson("/api/addresses/{$id}", array_merge($base, ['pickup_point_name' => 'New name']))->assertOk()->assertJsonPath('pickup_point_name', 'New name');
    }

    // --- historical addresses and orders ----------------------------------------------------------------------------------------

    public function test_a_historical_pickup_address_without_the_two_fields_still_checks_out_and_the_admin_view_renders(): void
    {
        $this->lockerMethod();
        $account = $this->customer();
        $historical = Address::create(AddressDeliveryType::PICKUP_POINT, 'Ivan Ivanov', '+359888111222', (string) $account->id, 'BG', carrierCode: 'econt', pickupPointReference: 'office-77', settlement: 'Sofia');
        app(AddressRepository::class)->save($historical);
        $this->assertNull(app(AddressRepository::class)->findById($historical->id())->pickupPointName());
        $cartId = $this->fillCart();

        $response = $this->postJson('/api/checkout', $this->checkoutPayload($cartId, ['address_id' => $historical->id()], ['email' => 'buyer@example.com']))->assertStatus(201);

        $response->assertJsonPath('order.pickup_point_name', null)->assertJsonPath('order.pickup_point_address', null)->assertJsonPath('order.pickup_point_reference', 'office-77');
        $order = DB::table('orders')->sole();
        $this->assertNull($order->pickup_point_name);
        $this->assertNull(DB::table('order_placement_snapshots')->sole()->pickup_point_name);

        $this->staff();
        $html = Livewire::test(ViewOrder::class, ['record' => $order->id])->html();
        $this->assertStringContainsString('office-77', $html);
        $this->assertStringNotContainsString(__('orders.fields.pickup_point_name'), $html, 'no empty label for an order without the text');
    }

    // --- the admin page --------------------------------------------------------------------------------------------------------

    public function test_the_admin_order_view_shows_the_office_name_and_address_for_a_pickup_order_in_both_languages_escaped_and_nothing_for_a_street_order(): void
    {
        $this->lockerMethod();
        $cartId = $this->fillCart();
        $this->postJson('/api/checkout', $this->checkoutPayload($cartId, $this->pickupFields(['pickup_point_name' => 'Office <b>"Center"</b> & Co'])))->assertStatus(201);
        $orderId = DB::table('orders')->sole()->id;
        $this->staff();

        app()->setLocale('en');
        $en = Livewire::test(ViewOrder::class, ['record' => $orderId])->html();
        $this->assertStringContainsString('Office name: Office &lt;b&gt;&#34;Center&#34;&lt;/b&gt; &amp; Co', $en, 'stored as typed, shown escaped');
        $this->assertStringNotContainsString('<b>"Center"</b>', $en);
        $this->assertStringContainsString('Office address: '.self::ADDRESS, $en);

        app()->setLocale('bg');
        $bg = Livewire::test(ViewOrder::class, ['record' => $orderId])->html();
        $this->assertStringContainsString('Име на офиса:', $bg);
        $this->assertStringContainsString('Адрес на офиса: '.self::ADDRESS, $bg);

        // A street order shows no such lines.
        $street = $this->streetOrder();
        app()->setLocale('en');
        $html = Livewire::test(ViewOrder::class, ['record' => $street->id()])->html();
        $this->assertStringNotContainsString('Office name', $html);
        $this->assertStringNotContainsString('Office address', $html);
    }

    private function streetOrder(): Order
    {
        $order = Order::create(
            clientId: $this->clientId(), transactionId: $this->transactionId(), email: 'b@example.com', currency: 'EUR',
            subtotal: Money::fromMinorUnits(1000, 'EUR'), discount: Money::zero('EUR'), deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'I I', phone: '+359888123456', placedAt: new DateTimeImmutable('2026-10-09 10:00:00'), country: 'BG', city: 'Sofia', addressLine1: 'V 1',
        );
        app(OrderRepository::class)->save($order);

        return $order;
    }

    private function clientId(): string
    {
        $client = new \EasyCo\OperationalSales\Client(null, 'Ivan Ivanov');
        app(\EasyCo\OperationalSales\Contracts\ClientRepository::class)->save($client);

        return $client->id();
    }

    private function transactionId(): string
    {
        $clientId = $this->clientId();
        $transaction = new \EasyCo\OperationalSales\Transaction(null, \EasyCo\OperationalSales\Enums\Channel::WEB);
        $transaction->addSaleLine(\EasyCo\OperationalSales\SaleLine::create(
            transactionId: '', clientId: $clientId, priceableId: 'variation-1', status: \EasyCo\OperationalSales\Enums\SaleLineStatus::COMPLETED, quantity: 1,
            amount: Money::fromMinorUnits(1000, 'EUR'), profit: Money::fromMinorUnits(200, 'EUR'), recordedAt: new DateTimeImmutable('2026-01-01'), effectiveAt: new DateTimeImmutable('2026-01-01'),
            productName: 'P', sku: 'S', regularUnitPrice: Money::fromMinorUnits(1000, 'EUR'), finalUnitPrice: Money::fromMinorUnits(1000, 'EUR'),
            promotionDiscountShare: Money::zero('EUR'), discretionaryDiscount: Money::zero('EUR'), netPaidAmount: Money::fromMinorUnits(1000, 'EUR'), soldAttributes: [],
        ));
        app(\EasyCo\OperationalSales\Contracts\TransactionRepository::class)->save($transaction);

        return $transaction->id();
    }

    // --- the entities and the database --------------------------------------------------------------------------------------------

    public function test_the_address_and_order_entities_apply_the_same_rules(): void
    {
        $pickup = Address::create(AddressDeliveryType::PICKUP_POINT, 'I I', '+359888123456', null, 'BG', carrierCode: 'econt', pickupPointReference: 'o-1', settlement: 'Sofia', pickupPointName: '  Name  ', pickupPointAddress: ' Addr ');
        $this->assertSame(['Name', 'Addr'], [$pickup->pickupPointName(), $pickup->pickupPointAddress()], 'trimmed');
        $none = Address::create(AddressDeliveryType::PICKUP_POINT, 'I I', '+359888123456', null, 'BG', carrierCode: 'econt', pickupPointReference: 'o-1', settlement: 'Sofia');
        $this->assertNull($none->pickupPointName(), 'a pickup address without them is legal (historical)');

        foreach ([
            fn () => Address::create(AddressDeliveryType::STREET_ADDRESS, 'I I', '+359888123456', null, 'BG', 'Sofia', addressLine1: 'V 1', pickupPointName: 'x'),
            fn () => Address::create(AddressDeliveryType::STREET_ADDRESS, 'I I', '+359888123456', null, 'BG', 'Sofia', addressLine1: 'V 1', pickupPointAddress: 'x'),
            fn () => Address::create(AddressDeliveryType::PICKUP_POINT, 'I I', '+359888123456', null, 'BG', carrierCode: 'e', pickupPointReference: 'o', settlement: 'S', pickupPointName: '   '),
            fn () => Address::create(AddressDeliveryType::PICKUP_POINT, 'I I', '+359888123456', null, 'BG', carrierCode: 'e', pickupPointReference: 'o', settlement: 'S', pickupPointAddress: str_repeat('x', 256)),
            fn () => $none->update(AddressDeliveryType::STREET_ADDRESS, 'I I', '+359888123456', 'BG', 'Sofia', addressLine1: 'V 1', pickupPointName: 'x'),
            fn () => Order::create(
                clientId: '1', transactionId: '1', email: 'b@example.com', currency: 'EUR', subtotal: Money::fromMinorUnits(1000, 'EUR'), discount: Money::zero('EUR'),
                deliveryType: OrderDeliveryType::STREET_ADDRESS, recipientName: 'I I', phone: '+359888123456', placedAt: new DateTimeImmutable(), country: 'BG', city: 'S', addressLine1: 'V', pickupPointName: 'x',
            ),
            fn () => Order::create(
                clientId: '1', transactionId: '1', email: 'b@example.com', currency: 'EUR', subtotal: Money::fromMinorUnits(1000, 'EUR'), discount: Money::zero('EUR'),
                deliveryType: OrderDeliveryType::PICKUP_POINT, recipientName: 'I I', phone: '+359888123456', placedAt: new DateTimeImmutable(), country: 'BG', carrierCode: 'e', pickupPointReference: 'o', settlement: 'S', pickupPointAddress: '  ',
            ),
        ] as $case) {
            try {
                $case();
                $this->fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_edit_of_the_delivery_keeps_the_display_text_for_the_same_office_and_clears_it_otherwise(): void
    {
        $make = fn (): Order => Order::create(
            clientId: '1', transactionId: '1', email: 'b@example.com', currency: 'EUR', subtotal: Money::fromMinorUnits(1000, 'EUR'), discount: Money::zero('EUR'),
            deliveryType: OrderDeliveryType::PICKUP_POINT, recipientName: 'I I', phone: '+359888123456', placedAt: new DateTimeImmutable(), country: 'BG',
            carrierCode: 'econt', pickupPointReference: 'office-1', settlement: 'Sofia', pickupPointName: self::NAME, pickupPointAddress: self::ADDRESS,
        );
        $revise = fn (Order $o, string $reference = 'office-1', OrderDeliveryType $type = OrderDeliveryType::PICKUP_POINT, ?string $name = null) => $o->reviseDelivery(
            $type, 'New Recipient', '+359888123456', 'BG',
            $type === OrderDeliveryType::STREET_ADDRESS ? 'Sofia' : null, null, $type === OrderDeliveryType::STREET_ADDRESS ? 'V 1' : null, null,
            $type === OrderDeliveryType::STREET_ADDRESS ? null : 'econt', $type === OrderDeliveryType::STREET_ADDRESS ? null : $reference, $type === OrderDeliveryType::STREET_ADDRESS ? null : 'Sofia', $name,
        );

        $same = $make();
        $revise($same);
        $this->assertSame([self::NAME, self::ADDRESS], [$same->pickupPointName(), $same->pickupPointAddress()], 'an unrelated edit does not wipe the snapshot');

        $other = $make();
        $revise($other, 'office-2');
        $this->assertSame([null, null], [$other->pickupPointName(), $other->pickupPointAddress()], 'another office: the old text would be wrong');

        $street = $make();
        $revise($street, type: OrderDeliveryType::STREET_ADDRESS);
        $this->assertSame([null, null], [$street->pickupPointName(), $street->pickupPointAddress()]);

        $named = $make();
        $revise($named, 'office-3', name: 'Brand new name');
        $this->assertSame(['Brand new name', null], [$named->pickupPointName(), $named->pickupPointAddress()]);
    }

    public function test_the_database_refuses_display_text_on_a_street_row_and_blank_text_on_all_three_tables(): void
    {
        $this->lockerMethod();
        $cartId = $this->fillCart();
        $this->postJson('/api/checkout', $this->checkoutPayload($cartId, $this->pickupFields()))->assertStatus(201);

        foreach ([['orders', 'ord_pickup_display_check'], ['addresses', 'addr_pickup_display_check'], ['order_placement_snapshots', 'ops_pickup_display_check']] as [$table, $check]) {
            foreach ([['delivery_type' => 'street_address'], ['pickup_point_name' => '   ']] as $bad) {
                try {
                    DB::table($table)->update($bad);
                    $this->fail("{$table} accepted ".json_encode($bad));
                } catch (QueryException $e) {
                    $this->assertSame(3819, $e->errorInfo[1]);
                    $this->assertStringContainsString($check, $e->getMessage());
                }
            }
        }
    }

    // --- nothing else moved --------------------------------------------------------------------------------------------------------

    public function test_the_quote_endpoint_and_the_resolver_are_unchanged(): void
    {
        $this->lockerMethod();
        $this->fillCart();

        $with = $this->postJson('/api/shipping/quote', ['delivery_type' => 'pickup_point', 'country' => 'BG', 'settlement' => 'Sofia', 'pickup_point_name' => 'ignored', 'pickup_point_address' => 'ignored'])->assertOk();
        $without = $this->postJson('/api/shipping/quote', ['delivery_type' => 'pickup_point', 'country' => 'BG', 'settlement' => 'Sofia'])->assertOk();

        $this->assertSame(['cart_id', 'currency', 'goods_after_discount', 'zone', 'methods', 'groups', 'free_shipping_hint'], array_keys($without->json()));
        $this->assertSame(array_keys($without->json('methods.0')), array_keys($with->json('methods.0')));
        $this->assertSame($without->json('methods.0.price'), $with->json('methods.0.price'), 'the new fields are not part of a quote');
        $this->assertStringNotContainsString('ignored', (string) $with->getContent());

        $parameters = array_map(fn ($p) => $p->getName(), (new ReflectionMethod(\App\Services\CheckoutShippingResolver::class, 'resolve'))->getParameters());
        $this->assertSame(['cart', 'accountId', 'destination', 'shippingMethodId', 'quoteHandle', 'expectedShippingMinor'], $parameters);
    }
}
