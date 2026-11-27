<?php

namespace Tests\Feature;

use App\Couriers\CourierDirectory;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * COUR-2a.6.1: wherever an order's or an address's courier is SHOWN it is the courier's NAME («Спиди»), whatever was stored: the code an order placed through the
 * list stores (`speedy`), the display name an older order stores («Спиди»), or a plain courier name (shown as it is). What is stored is never rewritten.
 */
class CourierNameDisplayTest extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRegionOrders();
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> stored, shown in bg, shown in en */
    public static function stored(): array
    {
        return [
            'a code' => ['speedy', 'Спиди', 'Speedy'],
            'a display name' => ['Спиди', 'Спиди', 'Speedy'],
            'the English name' => ['Speedy', 'Спиди', 'Speedy'],
            'spaces and case' => ["  СПИДИ\u{00A0}", 'Спиди', 'Speedy'],
            'econt by code' => ['econt', 'Еконт', 'Econt'],
            'box now by name' => ['BOX NOW', 'BOX NOW', 'BOX NOW'],
            'a plain courier name is shown as stored' => ['Local Courier', 'Local Courier', 'Local Courier'],
        ];
    }

    #[DataProvider('stored')]
    public function test_the_helper_names_the_courier_in_the_asked_language_and_leaves_other_text_alone(string $stored, string $bg, string $en): void
    {
        $directory = app(CourierDirectory::class);

        $this->assertSame($bg, $directory->displayName($stored, 'bg'));
        $this->assertSame($en, $directory->displayName($stored, 'en'));
    }

    public function test_nothing_stays_nothing(): void
    {
        $this->assertNull(app(CourierDirectory::class)->displayName(null));
    }

    #[DataProvider('stored')]
    public function test_the_admin_order_view_shows_the_name_and_the_stored_text_is_not_rewritten(string $stored, string $bg, string $en): void
    {
        $orderId = (string) $this->checkout($this->pickupFields(['carrier_code' => $stored]))->assertStatus(201)->json('order.id');
        $this->actingAsAdministrator();
        $this->app->forgetScopedInstances();
        app()->setLocale('bg');   // the panel's language; the name follows it

        $html = Livewire::test(ViewOrder::class, ['record' => $orderId])->html();

        $this->assertStringContainsString(__('orders.fields.carrier_code').': '.$bg, $html);
        $this->assertStringNotContainsString(__('orders.fields.carrier_code').': speedy', $html);
        // the courier's OWN office code keeps its label
        $this->assertStringContainsString(__('orders.fields.pickup_point_reference').': office-1', $html);
        // exactly what the request carried (the request's own trimming aside): the order is not rewritten to a code or a name
        $this->assertSame(trim($stored, " \t\n\r\0\x0B\u{00A0}"), DB::table('orders')->where('id', $orderId)->value('carrier_code'));
    }

    public function test_our_internal_point_number_is_labelled_as_internal(): void
    {
        $this->assertSame('Вътрешен № на точката', __('orders.fields.pickup_point_id', [], 'bg'));
        $this->assertSame('Internal point no.', __('orders.fields.pickup_point_id', [], 'en'));
        $this->assertSame('Офис на куриер', __('orders.fields.pickup_point_reference', [], 'bg'));
    }

    #[DataProvider('stored')]
    public function test_the_account_address_book_shows_the_name(string $stored, string $bg, string $en): void
    {
        $account = Account::register('courier-name@example.com', 'hashed-password');
        app(AccountRepository::class)->save($account);
        $this->actingAs(AccountModel::findOrFail($account->id()), 'customer');
        app(AddressRepository::class)->save(Address::create(
            deliveryType: AddressDeliveryType::PICKUP_POINT, recipientFirstName: 'Maria', recipientLastName: 'Ivanova', phone: '+359888765432', accountId: (string) $account->id(),
            country: 'BG', carrierCode: $stored, pickupPointReference: 'OFF-1', settlement: 'Plovdiv', pickupPointName: 'Office', pickupPointAddress: 'Vasil Levski 52', pickupPointKind: 'office',
        ));
        // the page shows what is stored; saving through the book would have mapped it, so the row is written back as it was
        DB::table('addresses')->update(['carrier_code' => $stored]);

        $html = (string) $this->get('/account/addresses')->assertOk()->getContent();

        // a pick-up address with a kind reads «courier / kind»
        $this->assertStringContainsString($bg.' / '.__('account_pages.address_kind_office'), html_entity_decode($html));
    }
}
