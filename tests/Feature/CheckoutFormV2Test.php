<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\StaffPanelUser;
use App\Services\OrderEditFormMapper;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * Checkout form v2, stage C1d-A (checkout-form-v2-design.md section 2 and 3), through the HTTP API and the admin: the two name fields
 * (the old single one is refused), where the first name and the full name are shown, and a pick-up point that is described by the
 * customer's own text. The storage rules are in RecipientNameSplitMigrationTest and in the two packages' own tests.
 */
class CheckoutFormV2Test extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->setUpRegionOrders();
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

    /** @return array{html: string, text: string} */
    private function mail(): array
    {
        $messages = array_values(Mail::mailer('array')->getSymfonyTransport()->messages()->all());
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        return ['html' => (string) $email->getHtmlBody(), 'text' => (string) $email->getTextBody()];
    }

    private function loggedInAccount(): AccountModel
    {
        $account = Account::register('user@example.com', 'hashed-password');
        app(AccountRepository::class)->save($account);
        $model = AccountModel::findOrFail($account->id());
        $this->actingAs($model, 'customer');

        return $model;
    }

    // ---- the API ----------------------------------------------------------------------------------------------------------------------

    public function test_the_old_single_name_alone_is_refused_on_both_new_fields(): void
    {
        $payload = $this->regionPayload($this->regionCart(), $this->streetFields('BG'));
        unset($payload['recipient_first_name'], $payload['recipient_last_name']);
        $payload['recipient_name'] = 'Ivan Ivanov';

        $this->postJson('/api/checkout', $payload)->assertStatus(422)->assertJsonValidationErrors(['recipient_first_name', 'recipient_last_name']);
        $this->assertSame(0, DB::table('orders')->count());

        $this->loggedInAccount();
        $address = ['delivery_type' => 'street_address', 'recipient_name' => 'Ivan Ivanov', 'phone' => '+359888123456', 'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'V 1'];
        $this->postJson('/api/addresses', $address)->assertStatus(422)->assertJsonValidationErrors(['recipient_first_name', 'recipient_last_name']);
    }

    public function test_each_name_part_is_limited_to_100_characters_in_both_endpoints(): void
    {
        $this->loggedInAccount();

        foreach (['recipient_first_name', 'recipient_last_name'] as $field) {
            $this->checkout($this->streetFields('BG'), [$field => str_repeat('я', 101)])->assertStatus(422)->assertJsonValidationErrors([$field]);

            $address = ['delivery_type' => 'street_address', 'recipient_first_name' => 'Ivan', 'recipient_last_name' => 'Ivanov', 'phone' => '+359888123456', 'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'V 1'];
            $this->postJson('/api/addresses', array_merge($address, [$field => str_repeat('я', 101)]))->assertStatus(422)->assertJsonValidationErrors([$field]);
            $this->postJson('/api/addresses', array_merge($address, [$field => str_repeat('я', 100)]))->assertStatus(201);
        }

        $this->checkout($this->streetFields('BG'), ['recipient_first_name' => str_repeat('я', 100), 'recipient_last_name' => str_repeat('ю', 100)])->assertStatus(201);
    }

    public function test_the_responses_carry_the_two_fields_and_no_single_name(): void
    {
        $response = $this->checkout($this->streetFields('BG'), ['recipient_first_name' => 'Сия', 'recipient_last_name' => 'Иванова'])->assertStatus(201);

        $this->assertSame(['Сия', 'Иванова'], [$response->json('order.recipient_first_name'), $response->json('order.recipient_last_name')]);
        $this->assertArrayNotHasKey('recipient_name', $response->json('order'));

        $this->loggedInAccount();
        $created = $this->postJson('/api/addresses', ['delivery_type' => 'street_address', 'recipient_first_name' => 'Сия', 'recipient_last_name' => 'Иванова', 'phone' => '+359888123456', 'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'V 1'])->assertStatus(201);
        $this->assertSame(['Сия', 'Иванова'], [$created->json('recipient_first_name'), $created->json('recipient_last_name')]);
        $this->assertArrayNotHasKey('recipient_name', $created->json());
        $this->assertArrayNotHasKey('recipient_name', $this->getJson('/api/addresses')->json('data.0'));
    }

    public function test_a_saved_address_brings_its_own_two_names_to_the_order(): void
    {
        $account = $this->loggedInAccount();
        $id = $this->postJson('/api/addresses', ['delivery_type' => 'street_address', 'recipient_first_name' => 'Сия', 'recipient_last_name' => 'Иванова', 'phone' => '+359888123456', 'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'V 1'])->json('id');

        $payload = $this->regionPayload($this->regionCart(), ['address_id' => (string) $id]);
        unset($payload['recipient_first_name'], $payload['recipient_last_name']);
        $payload['recipient_first_name'] = 'X';
        $payload['recipient_last_name'] = 'Y';

        $this->postJson('/api/checkout', $payload)->assertStatus(201);
        $this->assertSame($account->id, DB::table('orders')->value('account_id'));
        $this->assertSame(['Сия', 'Иванова'], [DB::table('orders')->value('recipient_first_name'), DB::table('orders')->value('recipient_last_name')]);
    }

    // ---- where the name is shown ----------------------------------------------------------------------------------------------------

    public function test_the_mail_greets_by_the_first_name_only_and_the_recipient_row_shows_the_full_name(): void
    {
        $this->checkout($this->streetFields('BG'), ['recipient_first_name' => 'Емил', 'recipient_last_name' => 'Петров'])->assertStatus(201);

        $mail = $this->mail();

        $this->assertStringContainsString('Hello Емил,', $mail['text']);
        $this->assertStringNotContainsString('Hello Емил Петров', $mail['text'], 'the greeting is the first name ONLY');
        $this->assertStringContainsString('Емил Петров', $mail['html'], 'the recipient row of the delivery block shows the full name');
    }

    public function test_the_order_received_page_greets_by_the_first_name_and_shows_the_full_name_in_the_destination(): void
    {
        $response = $this->checkout($this->streetFields('BG'), ['recipient_first_name' => 'Емил', 'recipient_last_name' => 'Петров'])->assertStatus(201);

        $html = $this->get($response->json('confirmation_url'))->assertOk()->getContent();

        $this->assertStringContainsString('<p class="sf-received-greeting">Hello, Емил</p>', $html);
        $this->assertMatchesRegularExpression('/<address class="sf-received-destination">\s*<span class="sf-received-destination-line">Емил Петров<\/span>/', $html);
    }

    public function test_the_admin_view_shows_both_parts_and_a_described_point_shows_its_text_without_a_code_line(): void
    {
        $this->checkout($this->pickupFields(['pickup_point_reference' => null, 'pickup_point_name' => null, 'pickup_point_address' => 'Speedy office, Vitosha Blvd 100']), ['recipient_first_name' => 'Сия', 'recipient_last_name' => 'Иванова'])->assertStatus(201);
        $orderId = DB::table('orders')->sole()->id;
        $this->staff();
        app()->setLocale('en');

        $html = Livewire::test(ViewOrder::class, ['record' => $orderId])->html();

        $this->assertStringContainsString('First name', $html);
        $this->assertStringContainsString('Last name', $html);
        $this->assertStringContainsString('Сия', $html);
        $this->assertStringContainsString('Иванова', $html);
        $this->assertStringContainsString('Speedy office, Vitosha Blvd 100', $html);
        $this->assertStringNotContainsString('Pickup point reference', $html, 'a described point has no code line');
    }

    public function test_staff_edit_both_name_parts_and_neither_may_be_blank(): void
    {
        $this->checkout($this->streetFields('BG'))->assertStatus(201);
        $orderId = DB::table('orders')->sole()->id;
        $this->staff();

        $component = Livewire::test(ViewOrder::class, ['record' => $orderId]);
        $component->mountAction(TestAction::make('edit_order')->schemaComponent(true, 'infolist'));
        $seeded = $component->instance()->mountedActions[0]['data'];
        $this->assertSame(['Guest', 'Buyer'], [$seeded['delivery']['recipient_first_name'], $seeded['delivery']['recipient_last_name']]);

        $component->fillForm(['lines' => $seeded['lines'], 'delivery' => array_merge($seeded['delivery'], ['recipient_last_name' => ''])])->callMountedAction()->assertHasFormErrors();
        $this->assertSame('Buyer', DB::table('orders')->value('recipient_last_name'));

        $component->fillForm(['lines' => $seeded['lines'], 'delivery' => array_merge($seeded['delivery'], ['recipient_first_name' => 'Сия', 'recipient_last_name' => 'Иванова'])])->callMountedAction();
        $this->assertSame(['Сия', 'Иванова'], [DB::table('orders')->value('recipient_first_name'), DB::table('orders')->value('recipient_last_name')]);
        $this->assertSame(['Guest', 'Buyer'], [DB::table('order_placement_snapshots')->value('recipient_first_name'), DB::table('order_placement_snapshots')->value('recipient_last_name')], 'the placement snapshot is write-once');
    }

    public function test_the_editor_offers_the_one_point_field_and_shows_a_code_read_only_only_when_the_row_has_one(): void
    {
        $this->checkout($this->pickupFields(['pickup_point_reference' => null, 'pickup_point_name' => null, 'pickup_point_address' => 'Speedy office']))->assertStatus(201);
        $orderId = DB::table('orders')->sole()->id;
        $this->staff();

        $component = Livewire::test(ViewOrder::class, ['record' => $orderId]);
        $component->mountAction(TestAction::make('edit_order')->schemaComponent(true, 'infolist'));
        $seeded = $component->instance()->mountedActions[0]['data'];

        $this->assertSame('Speedy office', $seeded['delivery']['pickup_point_address']);
        $component->fillForm(['lines' => $seeded['lines'], 'delivery' => array_merge($seeded['delivery'], ['pickup_point_address' => 'Econt locker, Mall'])])->callMountedAction();
        $this->assertSame([null, 'Econt locker, Mall'], [DB::table('orders')->value('pickup_point_reference'), DB::table('orders')->value('pickup_point_address')]);

        // The action closed after saving: mount it again for the refusal.
        $again = Livewire::test(ViewOrder::class, ['record' => $orderId]);
        $again->mountAction(TestAction::make('edit_order')->schemaComponent(true, 'infolist'));
        $seeded = $again->instance()->mountedActions[0]['data'];
        $again->fillForm(['lines' => $seeded['lines'], 'delivery' => array_merge($seeded['delivery'], ['pickup_point_address' => ''])])->callMountedAction()->assertHasFormErrors();
        $this->assertSame('Econt locker, Mall', DB::table('orders')->value('pickup_point_address'));
    }

    public function test_the_form_mapper_reads_the_two_parts_and_the_point_text(): void
    {
        $current = array_fill_keys(OrderEditFormMapper::DELIVERY_FIELDS, null);
        $current += ['delivery_type' => 'pickup_point'];
        $current = array_merge($current, ['delivery_type' => 'pickup_point', 'recipient_first_name' => 'Ivan', 'recipient_last_name' => 'Ivanov', 'phone' => '1', 'country' => 'BG', 'carrier_code' => 'econt', 'settlement' => 'Sofia', 'pickup_point_address' => 'Old text']);

        $change = OrderEditFormMapper::deliveryChange($current, array_merge($current, ['recipient_last_name' => 'Petrov', 'pickup_point_address' => 'New text']));

        $this->assertSame(['Ivan', 'Petrov', 'New text'], [$change->recipientFirstName, $change->recipientLastName, $change->pickupPointAddress]);
        $this->assertNull(OrderEditFormMapper::deliveryChange($current, $current), 'nothing changed');
    }

    // ---- the pick-up point: described by the customer's text -----------------------------------------------------------------

    public function test_a_described_point_checks_out_with_the_address_text_alone_and_the_order_stores_it(): void
    {
        $response = $this->checkout($this->pickupFields(['pickup_point_reference' => null, 'pickup_point_name' => null, 'pickup_point_address' => 'Speedy, ул. Витоша 100', 'pickup_point_kind' => 'office']))->assertStatus(201);

        $this->assertSame([null, null, 'Speedy, ул. Витоша 100', 'office'], [
            DB::table('orders')->value('pickup_point_reference'), DB::table('orders')->value('pickup_point_name'),
            DB::table('orders')->value('pickup_point_address'), DB::table('orders')->value('pickup_point_kind'),
        ]);
        $this->assertSame('Speedy, ул. Витоша 100', $response->json('order.pickup_point_address'));
        $this->assertSame('Speedy, ул. Витоша 100', DB::table('order_placement_snapshots')->value('pickup_point_address'));
    }

    public function test_a_described_point_saves_as_an_address_and_a_saved_one_checks_out(): void
    {
        $account = $this->loggedInAccount();
        $id = $this->postJson('/api/addresses', [
            'delivery_type' => 'pickup_point', 'recipient_first_name' => 'Ivan', 'recipient_last_name' => 'Ivanov', 'phone' => '+359888123456', 'country' => 'BG',
            'carrier_code' => 'econt', 'settlement' => 'Sofia', 'pickup_point_address' => 'Econt office, Vitosha 5',
        ])->assertStatus(201)->json('id');
        $this->assertNull(DB::table('addresses')->where('id', $id)->value('pickup_point_reference'));

        $payload = array_merge($this->regionPayload($this->regionCart(), ['address_id' => (string) $id]), $this->shippingPayload(true));
        $this->postJson('/api/checkout', $payload)->assertStatus(201);
        $this->assertSame([$account->id, 'Econt office, Vitosha 5'], [DB::table('orders')->value('account_id'), DB::table('orders')->value('pickup_point_address')]);
    }

    public function test_a_pickup_order_with_none_of_the_three_is_refused_on_the_description(): void
    {
        $this->checkout($this->pickupFields(['pickup_point_reference' => null, 'pickup_point_name' => null, 'pickup_point_address' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['pickup_point_address']);

        $this->checkout($this->pickupFields(['pickup_point_reference' => 'office-1', 'pickup_point_name' => 'Name', 'pickup_point_address' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['pickup_point_address']);

        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_a_street_address_still_refuses_all_three_pickup_fields(): void
    {
        foreach (['pickup_point_reference', 'pickup_point_name', 'pickup_point_address'] as $field) {
            $this->checkout($this->streetFields('BG', [$field => 'Something']))->assertStatus(422)->assertJsonValidationErrors([$field]);
        }
    }

    public function test_an_order_with_all_three_values_is_stored_and_shown_as_before(): void
    {
        $response = $this->checkout($this->pickupFields())->assertStatus(201);

        $this->assertSame(['office-1', 'Econt Center', 'Vitosha Blvd 100'], [
            DB::table('orders')->value('pickup_point_reference'), DB::table('orders')->value('pickup_point_name'), DB::table('orders')->value('pickup_point_address'),
        ]);

        $page = $this->get($response->json('confirmation_url'))->assertOk()->getContent();
        $this->assertStringContainsString('Econt Center', $page);
        $this->assertStringContainsString('Vitosha Blvd 100', $page);
    }
}
