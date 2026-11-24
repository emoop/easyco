<?php

namespace Tests\Feature\Customers;

use App\Customers\CustomerIdentity;
use App\Customers\CustomerListQuery;
use App\Customers\CustomerPageReader;
use App\Customers\CustomerRow;
use App\Filament\Pages\CustomerPage;
use App\Filament\Pages\Customers;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\InsertsCustomerOrders;
use Tests\Feature\Account\Concerns\DrivesAccountApi;
use Tests\TestCase;

/**
 * BUG-2: the name and the phone of a customer in the admin: the account has no name column, so they come from the account's saved address (the default one), else from the latest order.
 * The title, the «Данни» card and the «Клиенти» list row must all show the same resolved value (App\Customers\CustomerContact).
 */
class CustomerContactTest extends TestCase
{
    use BuildsShippingZones;
    use DrivesAccountApi;
    use InsertsCustomerOrders;
    use RefreshDatabase;

    private int $queryCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteSettingsRepository::class)->set('site.country', 'BG');
        DB::listen(function (): void {
            $this->queryCount++;
        });
    }

    private function address(int $account, string $first, string $last, string $phone, bool $default = false): int
    {
        return (int) DB::table('addresses')->insertGetId([
            'account_id' => $account, 'delivery_type' => 'street_address', 'recipient_first_name' => $first, 'recipient_last_name' => $last, 'phone' => $phone,
            'country' => 'BG', 'city' => 'Sofia', 'postal_code' => '1000', 'address_line_1' => 'Vitosha 1', 'default_marker' => $default ? 1 : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function page(string $key): Testable
    {
        return Livewire::test(CustomerPage::class, ['key' => $key]);
    }

    /** the row of the list for this email */
    private function row(string $email): ?CustomerRow
    {
        return CustomerListQuery::query()->where('customers.email_key', $email)->first();
    }

    /** @return array{title: string, name: ?string, phone: ?string} what the customer page shows */
    private function shown(string $key): array
    {
        $page = $this->page($key);
        $person = app(CustomerPageReader::class)->read(CustomerIdentity::resolve($key))['person'];

        return ['title' => $page->instance()->getTitle(), 'name' => $person['name'], 'phone' => $person['phone']];
    }

    // ---- the four cases of the report -----------------------------------------------------------------------------------------------

    public function test_an_account_with_no_order_and_a_saved_address_shows_the_address_name_and_phone_everywhere(): void
    {
        $this->actingAsStaff('Administrator');
        $account = $this->insertAccount('ana@example.com');
        $this->address($account, 'Ана', 'Петрова', '+359888222222', default: true);

        $shown = $this->shown('a-'.$account);
        $this->assertSame('Ана Петрова', $shown['title']);
        $this->assertSame('Ана Петрова', $shown['name']);
        $this->assertSame('+359888222222', $shown['phone']);

        $page = $this->page('a-'.$account);
        $page->assertSee('Ана Петрова')->assertSee('+359888222222');

        $row = $this->row('ana@example.com');
        $this->assertSame('Ана Петрова', $row->name);
        $this->assertSame('+359888222222', $row->phone);
        $this->assertSame('a-'.$account, $row->row_key);
    }

    public function test_an_account_with_no_name_anywhere_shows_the_email_in_the_title_and_empty_cells(): void
    {
        $this->actingAsStaff('Administrator');
        $account = $this->insertAccount('nobody@example.com');

        $shown = $this->shown('a-'.$account);
        $this->assertSame('nobody@example.com', $shown['title']);
        $this->assertNull($shown['name']);
        $this->assertNull($shown['phone']);

        $row = $this->row('nobody@example.com');
        $this->assertNull($row->name);
        $this->assertNull($row->phone);

        $this->page('a-'.$account)->assertDontSeeHtml('>null<')->assertDontSeeHtml('> null <');
        Livewire::test(Customers::class)->loadTable()->assertSee('nobody@example.com')->assertDontSeeHtml('>null<');
    }

    public function test_a_guest_with_only_an_order_shows_the_order_name_and_phone(): void
    {
        $this->actingAsStaff('Administrator');
        $id = $this->insertOrder(['email' => 'guest@example.com', 'first' => 'Георги', 'last' => 'Димитров', 'phone' => '+359887333333']);

        $shown = $this->shown('o-'.$id);
        $this->assertSame('Георги Димитров', $shown['title']);
        $this->assertSame('+359887333333', $shown['phone']);

        $row = $this->row('guest@example.com');
        $this->assertSame('Георги Димитров', $row->name);
        $this->assertSame('+359887333333', $row->phone);
    }

    public function test_an_email_with_an_account_and_orders_prefers_the_default_address_over_the_order(): void
    {
        $this->actingAsStaff('Administrator');
        $account = $this->insertAccount('both@example.com');
        $this->address($account, 'Стара', 'Адресова', '+359888000001');
        $this->address($account, 'Мария', 'Иванова', '+359888000002', default: true);
        $id = $this->insertOrder(['email' => 'both@example.com', 'first' => 'Order', 'last' => 'Person', 'phone' => '+359888000003']);

        $shown = $this->shown('o-'.$id);
        $this->assertSame('Мария Иванова', $shown['title'], 'the default address wins over the order and over a non-default address');
        $this->assertSame('+359888000002', $shown['phone']);

        $row = $this->row('both@example.com');
        $this->assertSame('Мария Иванова', $row->name);
        $this->assertSame('+359888000002', $row->phone);
    }

    public function test_without_a_default_address_the_oldest_address_is_used_and_an_account_without_address_falls_back_to_the_order(): void
    {
        $this->actingAsStaff('Administrator');
        $account = $this->insertAccount('nodefault@example.com');
        $this->address($account, 'Първа', 'Първова', '+359888000011');
        $this->address($account, 'Втора', 'Второва', '+359888000012');
        $this->insertOrder(['email' => 'nodefault@example.com', 'first' => 'Order', 'last' => 'Person']);
        $this->assertSame('Първа Първова', $this->row('nodefault@example.com')->name);

        $this->insertAccount('noaddr@example.com');
        $this->insertOrder(['email' => 'noaddr@example.com', 'first' => 'Order', 'last' => 'Only', 'phone' => '+359888000013']);
        $row = $this->row('noaddr@example.com');
        $this->assertSame('Order Only', $row->name);
        $this->assertSame('+359888000013', $row->phone);
    }

    public function test_a_deleted_account_does_not_lend_its_address(): void
    {
        $account = $this->insertAccount('gone@example.com', deleted: true);
        $this->address($account, 'Скрит', 'Адрес', '+359888000021', default: true);
        $this->insertOrder(['email' => 'gone@example.com', 'first' => 'Order', 'last' => 'Name']);

        $this->assertSame('Order Name', $this->row('gone@example.com')->name);
    }

    public function test_the_name_the_account_saves_through_the_account_area_is_what_the_admin_shows(): void
    {
        $this->driveAccountApi();
        $this->withoutMiddleware(ThrottleRequests::class);
        $account = $this->verifiedAccount('real@example.com');
        $this->login('real@example.com')->assertOk();

        $this->postJson('/api/addresses', [
            'delivery_type' => 'street_address', 'recipient_first_name' => 'Петър', 'recipient_last_name' => 'Петров', 'phone' => '+359888123456',
            'country' => 'BG', 'city' => 'Sofia', 'postal_code' => '1000', 'address_line_1' => 'Vitosha Blvd 1',
        ])->assertStatus(201);

        $stored = DB::table('addresses')->where('account_id', $account->getKey())->first();
        $this->assertSame('Петър', $stored->recipient_first_name);
        $this->assertSame('+359888123456', $stored->phone);
        $this->assertSame(1, (int) $stored->default_marker, 'the first address is the default');

        $row = $this->row('real@example.com');
        $this->assertSame('Петър Петров', $row->name);
        $this->assertSame('+359888123456', $row->phone);
    }

    // ---- the list: search, count of queries, permission -----------------------------------------------------------------------------

    public function test_the_search_finds_a_person_by_the_name_and_the_phone_of_the_address(): void
    {
        $account = $this->insertAccount('findme@example.com');
        $this->address($account, 'Радослав', 'Търсенов', '+359888777666', default: true);
        $this->insertAccount('other@example.com');

        $byName = CustomerListQuery::search(CustomerListQuery::query(), 'радослав търс')->pluck('email_key')->all();
        $this->assertSame(['findme@example.com'], $byName);

        $byPhone = CustomerListQuery::search(CustomerListQuery::query(), '0888 777 666')->pluck('email_key')->all();
        $this->assertSame(['findme@example.com'], $byPhone);

        $this->assertSame([], CustomerListQuery::search(CustomerListQuery::query(), 'несъществуващ')->pluck('email_key')->all());
    }

    public function test_the_list_has_the_same_number_of_queries_for_one_row_and_for_twenty_five(): void
    {
        $this->actingAsStaff('Administrator');
        $a = $this->insertAccount('one@example.com');
        $this->address($a, 'Една', 'Единична', '+359888500001', default: true);

        $component = Livewire::test(Customers::class)->loadTable();
        $this->queryCount = 0;
        $component->call('$refresh');
        $one = $this->queryCount;

        for ($i = 2; $i <= 30; $i++) {
            $acc = $this->insertAccount("p{$i}@example.com");
            $this->address($acc, 'Име'.$i, 'Фамилия'.$i, '+3598885'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), default: true);
            $i % 2 === 0 && $this->insertOrder(['email' => "p{$i}@example.com"]);
        }

        $component = Livewire::test(Customers::class)->loadTable();
        $this->assertSame(25, $component->instance()->getTableRecords()->count());
        $this->queryCount = 0;
        $component->call('$refresh');
        $many = $this->queryCount;

        fwrite(STDERR, "\n[query-count] BUG-2 list render: 1 row = {$one}, 25 rows = {$many}\n");
        $this->assertSame($one, $many, 'no per-row query');
    }

    public function test_the_permission_is_unchanged(): void
    {
        $this->actingAsStaff('Manager');
        $this->get('/admin/customers')->assertForbidden();
    }

    // ---- «Забрави» ------------------------------------------------------------------------------------------------------------------

    public function test_after_the_erase_the_address_name_and_phone_show_nowhere(): void
    {
        $this->actingAsStaff('Administrator');
        Queue::fake();
        $account = $this->insertAccount('forget@example.com');
        $this->address($account, 'Забравяне', 'Адресово', '+359888909090', default: true);
        $id = $this->insertOrder(['email' => 'forget@example.com', 'first' => 'Order', 'last' => 'Person', 'phone' => '+359888909091']);

        $this->page('o-'.$id)->callAction('forget', ['reason' => 'Customer asked by email']);

        // the address is gone with the account, so its name and phone are no longer a source: the order's (kept until its retention, ACC-4b) is what is left
        $row = $this->row('forget@example.com');
        $this->assertSame('Order Person', $row->name);
        $this->assertSame('+359888909091', $row->phone);
        $this->assertSame('Order Person', $this->shown('o-'.$id)['title']);
        $this->page('o-'.$id)->assertDontSee('Забравяне')->assertDontSee('+359888909090');
        Livewire::test(Customers::class)->loadTable()->assertDontSee('Забравяне')->assertDontSee('+359888909090');

        // an account with no order disappears from the list altogether
        $lonely = $this->insertAccount('lonely2@example.com');
        $this->address($lonely, 'Самотна', 'Адресова', '+359888808080', default: true);
        Livewire::test(CustomerPage::class, ['key' => 'a-'.$lonely])->callAction('forget', ['reason' => 'Customer asked by email']);
        $this->assertNull($this->row('lonely2@example.com'));
        Livewire::test(Customers::class)->loadTable()->assertDontSee('Самотна')->assertDontSee('+359888808080');
    }

    public function test_the_reader_is_the_page_source(): void
    {
        $account = $this->insertAccount('reader@example.com');
        $this->address($account, 'Читател', 'Четов', '+359888606060', default: true);
        $identity = CustomerIdentity::resolve('a-'.$account);

        $person = app(CustomerPageReader::class)->read($identity)['person'];
        $this->assertSame('Читател Четов', $person['name']);
        $this->assertSame('+359888606060', $person['phone']);
    }
}
