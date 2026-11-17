<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\StaffPanelUser;
use App\Services\CheckoutInput;
use App\Services\CheckoutOrchestrator;
use DateTimeImmutable;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLineAdder;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Order\Order;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\ProvidesCheckoutShipping;
use Tests\TestCase;

/**
 * ORD-PC review: the admin ORDER EDIT dialog is the only admin action that changes an order's delivery data (OrderResource::editAction() →
 * OrderEditFormMapper::deliveryChange() → OrderEditor → Order::reviseDelivery() → the repository). These tests pin its rules for the ADDRESS:
 *  - an edit that does not touch the address leaves EVERY address column byte-for-byte as it was (the region and the postal code included);
 *  - the dialog shows and saves the region and the postal code of a street address, and changing the street alone keeps both;
 *  - changing the postal code is what the admin region line follows;
 *  - switching to an office / locker clears the street-only fields (the domain's rule), and no postcode is invented.
 */
class OrderEditKeepsAddressTest extends TestCase
{
    use ProvidesCheckoutShipping;
    use RefreshDatabase;

    /** every column of `orders` that holds the delivery address / recipient */
    private const ADDRESS_COLUMNS = [
        'delivery_type', 'recipient_first_name', 'recipient_last_name', 'phone', 'country', 'city', 'postal_code', 'address_line_1', 'address_line_2', 'region',
        'carrier_code', 'pickup_point_reference', 'settlement', 'pickup_point_name', 'pickup_point_address', 'pickup_point_kind',
    ];

    private ?PriceList $priceList = null;

    private ?string $variationId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $role = app(RoleRepository::class)->findSystemRoleByName('Administrator');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run(app(RoleRepository::class));
            $role = app(RoleRepository::class)->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create('admin-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), 'Administrator', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');
        session()->forget('password_hash_staff');

        app()->setLocale('bg');
    }

    private function variation(): string
    {
        $product = Product::createSimple('Alpha Widget', 'SKU-'.Str::upper(Str::random(6)), 'slug-'.Str::lower(Str::random(6)));
        app(ProductRepository::class)->save($product);
        $id = $product->variations()[0]->id();

        $this->priceList ??= tap(PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0), fn (PriceList $list) => app(PriceListRepository::class)->save($list));
        app(PriceListItemRepository::class)->save(new PriceListItem(null, $this->priceList->id(), PriceListItemTargetType::VARIATION, $id, Price::exclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 0)));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($id, 50));

        return $id;
    }

    /** a real order, placed through the real checkout: the full street address of the owner's example, or ($office) an OFFICE order in Харманли — no postcode, no region, no street */
    private function place(bool $office = false): Order
    {
        $this->variationId ??= $this->variation();
        $cart = Cart::forGuest((string) Str::uuid(), new DateTimeImmutable('+10 days'));
        app(CartLineAdder::class)->addLine($cart, $this->variationId, 2, null, null);

        return app(CheckoutOrchestrator::class)->place(new CheckoutInput(
            cartId: $cart->id(),
            email: 'guest@example.com',
            recipientFirstName: 'Иван', recipientLastName: 'Иванов',
            phone: '+359888000000',
            paymentMethod: 'cash_on_delivery',
            accountId: null,
            guestCartToken: app(CartRepository::class)->findById($cart->id())?->sessionToken(),
            addressId: null,
            deliveryType: $office ? AddressDeliveryType::PICKUP_POINT : AddressDeliveryType::STREET_ADDRESS,
            country: 'BG',
            city: $office ? null : 'Харманли',
            postalCode: $office ? null : '6450',
            addressLine1: $office ? null : 'ул. Васил Левски 52',
            addressLine2: $office ? null : 'вх. Б',
            region: $office ? null : 'Хасково',
            carrierCode: $office ? 'speedy' : null,
            settlement: $office ? 'Харманли' : null,
            pickupPointAddress: $office ? 'Ул. Васил Левски 52' : null,
            pickupPointKind: $office ? 'office' : null,
            shippingMethodId: $this->shippingMethodId($office), quoteHandle: $this->lostShippingHandle(), expectedShippingMinor: 0,
        ), new DateTimeImmutable('2026-09-29 12:00:00'))->order();
    }

    private function mount(Order $order): Testable
    {
        $component = Livewire::test(ViewOrder::class, ['record' => $order->id()]);
        $component->mountAction(TestAction::make('edit_order')->schemaComponent(true, 'infolist'));
        $this->assertNotEmpty($component->instance()->mountedActions, 'edit_order did not mount');

        return $component;
    }

    /** @return array<string, mixed> */
    private function seeded(Testable $component): array
    {
        return $component->instance()->mountedActions[0]['data'];
    }

    /** @return array<string, mixed> every address column of the order, as stored */
    private function address(string $orderId): array
    {
        $row = (array) DB::table('orders')->where('id', $orderId)->first(self::ADDRESS_COLUMNS);

        return $row;
    }

    private function row(string $orderId): object
    {
        return DB::table('orders')->where('id', $orderId)->first();
    }

    public function test_the_placed_order_really_stores_the_postcode_and_the_region(): void
    {
        $order = $this->place();

        $this->assertSame('6450', $this->row($order->id())->postal_code);
        $this->assertSame('Хасково', $this->row($order->id())->region);
    }

    public function test_the_dialog_shows_the_region_and_the_postal_code_of_a_street_address(): void
    {
        $order = $this->place();
        $delivery = $this->seeded($this->mount($order))['delivery'];

        $this->assertSame('6450', $delivery['postal_code']);
        $this->assertSame('Хасково', $delivery['region']);
        $this->assertSame('вх. Б', $delivery['address_line_2']);
    }

    /** ★ an edit that does not touch the address leaves every address column byte-for-byte unchanged (here: a line discount only) */
    public function test_an_edit_that_does_not_touch_the_address_leaves_every_address_column_unchanged(): void
    {
        $order = $this->place();
        $before = $this->address($order->id());

        $component = $this->mount($order);
        $lines = $this->seeded($component)['lines'];
        $key = array_key_first($lines);
        $lines[$key]['discount'] = '1.00';
        $component->fillForm(['lines' => $lines])->callMountedAction();

        $this->assertSame(1, (int) $this->row($order->id())->edit_revision, 'the edit really happened');
        $this->assertSame($before, $this->address($order->id()), 'every address column is byte-for-byte what it was');
    }

    /** an edit that submits the dialog's own address state back unchanged is also "nothing to change" for the delivery */
    public function test_resubmitting_the_address_as_shown_changes_nothing(): void
    {
        $order = $this->place();
        $before = $this->address($order->id());

        $component = $this->mount($order);
        $lines = $this->seeded($component)['lines'];
        $lines[array_key_first($lines)]['discount'] = '0.50';
        $component->fillForm(['lines' => $lines, 'delivery' => $this->seeded($component)['delivery']])->callMountedAction();

        $this->assertSame($before, $this->address($order->id()));
    }

    public function test_changing_only_the_street_keeps_the_region_and_the_postal_code(): void
    {
        $order = $this->place();
        $component = $this->mount($order);
        $delivery = $this->seeded($component)['delivery'];
        $delivery['address_line_1'] = 'ул. Георги Димитров 7';

        $component->fillForm(['delivery' => $delivery])->callMountedAction();

        $row = $this->row($order->id());
        $this->assertSame('ул. Георги Димитров 7', $row->address_line_1);
        $this->assertSame('6450', $row->postal_code);
        $this->assertSame('Хасково', $row->region);
        $this->assertSame('Харманли', $row->city);
        $this->assertSame('вх. Б', $row->address_line_2);
    }

    /** ★ the edit save carries the region: dropping it from the save would blank it */
    public function test_changing_the_region_in_the_dialog_is_saved(): void
    {
        $order = $this->place();
        $component = $this->mount($order);
        $delivery = $this->seeded($component)['delivery'];
        $delivery['region'] = 'Пловдив';

        $component->fillForm(['delivery' => $delivery])->callMountedAction();

        $this->assertSame('Пловдив', $this->row($order->id())->region);
        $this->assertSame('6450', $this->row($order->id())->postal_code, 'the postal code is untouched by a region change');
    }

    public function test_changing_the_postal_code_is_saved_and_the_admin_region_line_follows_it(): void
    {
        $order = $this->place();
        $component = $this->mount($order);
        $delivery = $this->seeded($component)['delivery'];
        $delivery['postal_code'] = '4000';
        $delivery['city'] = 'Пловдив';
        $delivery['region'] = null;

        $component->fillForm(['delivery' => $delivery])->callMountedAction();

        $row = $this->row($order->id());
        $this->assertSame('4000', $row->postal_code);

        $html = (new \ReflectionMethod(OrderResource::class, 'deliveryAddressHtml'))->invoke(null, \EasyCo\Order\Persistence\Eloquent\OrderModel::find($order->id()));
        $lines = array_map(static fn (string $line): string => trim(strip_tags($line)), explode('<br>', $html));

        $this->assertContains('Пловдив 4000', $lines);
        $this->assertContains('Пловдив', $lines, 'the region line is the derived one for the new code');
        $this->assertNotContains('Хасково', $lines);
    }

    public function test_switching_to_an_office_clears_the_street_only_fields_and_invents_no_postcode(): void
    {
        $order = $this->place();
        $component = $this->mount($order);

        $component->fillForm(['delivery' => [
            'delivery_type' => 'pickup_point',
            'recipient_first_name' => 'Иван', 'recipient_last_name' => 'Иванов',
            'phone' => '+359888000000',
            'country' => 'BG',
            'carrier_code' => 'speedy',
            'pickup_point_address' => 'Ул. Васил Левски 52',
            'settlement' => 'Харманли',
        ]])->callMountedAction();

        $row = $this->row($order->id());
        $this->assertSame('pickup_point', $row->delivery_type);

        foreach (['city', 'postal_code', 'address_line_1', 'address_line_2', 'region'] as $column) {
            $this->assertNull($row->{$column}, "{$column} is cleared for a pick-up point (the domain's rule)");
        }

        $this->assertSame('Харманли', $row->settlement);
    }

    /** the names of the fields the mounted edit dialog's delivery section builds (hidden ones included), with whether each is visible for the current state */
    private function deliveryFields(Testable $component): array
    {
        $found = [];

        $instance = $component->instance();
        // the mounted action's schema is a protected accessor of the page: read it the way the page itself does
        $schema = \Closure::bind(fn () => $this->getMountedActionSchema(), $instance, $instance::class)();

        foreach ($schema->getFlatFields(withHidden: true) as $field) {
            $path = method_exists($field, 'getStatePath') ? (string) $field->getStatePath(isAbsolute: false) : '';

            if (str_starts_with($path, 'delivery.')) {
                $found[substr($path, 9)] = $field->isVisible();
            }
        }

        return $found;
    }

    /** a street order with a stored region: the dialog HAS the region field, prefilled; submitted unchanged, the region and the postal code stay */
    public function test_the_edit_dialog_of_a_street_order_has_the_region_field_prefilled_and_saves_it_unchanged(): void
    {
        $order = $this->place();
        $component = $this->mount($order);

        $fields = $this->deliveryFields($component);
        $this->assertArrayHasKey('region', $fields, 'the region field is in the dialog');
        $this->assertTrue($fields['region'], 'and it is shown for a street address');
        $this->assertArrayHasKey('postal_code', $fields);
        $this->assertTrue($fields['postal_code']);
        $this->assertSame('Хасково', $this->seeded($component)['delivery']['region'], 'prefilled');
        $this->assertSame('6450', $this->seeded($component)['delivery']['postal_code'], 'prefilled');

        $before = $this->address($order->id());
        $component->fillForm(['delivery' => $this->seeded($component)['delivery']])->callMountedAction();

        $row = $this->row($order->id());
        $this->assertSame('Хасково', $row->region);
        $this->assertSame('6450', $row->postal_code);
        $this->assertSame($before, $this->address($order->id()), 'nothing else moved either');
        $this->assertSame(0, (int) $row->edit_revision, 'an unchanged submit is «nothing to change», not an edit');
    }

    /** an office order (the owner's): no region field in the dialog; a submit keeps region NULL and the admin still derives the region from the settlement */
    public function test_the_edit_dialog_of_an_office_order_has_no_region_field_and_the_admin_still_derives_the_region(): void
    {
        $order = $this->place(office: true);

        $row = $this->row($order->id());
        $this->assertSame('pickup_point', $row->delivery_type);
        $this->assertNull($row->region);
        $this->assertNull($row->postal_code);

        $component = $this->mount($order);
        $fields = $this->deliveryFields($component);
        $this->assertArrayHasKey('region', $fields, 'the field exists in the form definition...');
        $this->assertFalse($fields['region'], '...and is NOT shown for an office');
        $this->assertFalse($fields['postal_code']);
        $this->assertNull($this->seeded($component)['delivery']['region'] ?? null);

        $before = $this->address($order->id());
        $lines = $this->seeded($component)['lines'];
        $lines[array_key_first($lines)]['discount'] = '1.00';
        $component->fillForm(['lines' => $lines, 'delivery' => $this->seeded($component)['delivery']])->callMountedAction();

        $row = $this->row($order->id());
        $this->assertSame(1, (int) $row->edit_revision, 'the edit happened');
        $this->assertNull($row->region, 'the region stays null');
        $this->assertNull($row->postal_code, 'and no postcode appears');
        $this->assertSame($before, $this->address($order->id()));

        $html = (new \ReflectionMethod(OrderResource::class, 'deliveryAddressHtml'))->invoke(null, \EasyCo\Order\Persistence\Eloquent\OrderModel::find($order->id()));
        $lines = array_map(static fn (string $line): string => trim(strip_tags($line)), explode('<br>', $html));
        $this->assertContains('Хасково (по населеното място)', $lines, 'the admin still shows the region derived from the settlement');
    }
}
