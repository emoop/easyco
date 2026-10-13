<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\StaffPanelUser;
use App\Services\OrderDeliveryChange;
use App\Services\OrderEditFormMapper;
use App\Services\OrderEditor;
use App\Services\OrderPromotionCodeChange;
use App\Settings\Contracts\SiteSettingsRepository;
use DateTimeImmutable;
use EasyCo\Order\Enums\OrderDeliveryType;
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
 * Checkout RN-1 (checkout-region-and-note-design.md 2.5, 3, 5): where the region and the customer's note are SHOWN — the confirmation mail
 * (QUEUE_CONNECTION=sync and MAIL_MAILER=array in phpunit.xml, so a real checkout sends it inline), the order-received page, the admin
 * order view — always escaped, nothing at all when there is none; and the admin editor: the region editable for a street address, the
 * note never.
 */
class RegionNoteDisplayTest extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;

    private const SCRIPT = '<script>alert(1)</script>';

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

    /** @return array{html: string, text: string} the only mail sent so far */
    private function mail(): array
    {
        $messages = array_values(Mail::mailer('array')->getSymfonyTransport()->messages()->all());
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        return ['html' => (string) $email->getHtmlBody(), 'text' => (string) $email->getTextBody()];
    }

    // ---- the confirmation mail ---------------------------------------------------------------------------------------------------

    public function test_the_mail_shows_the_region_after_the_city_and_the_note_as_the_customers_words(): void
    {
        $this->checkout($this->streetFields('BG', ['region' => 'Sofia-city']), ['customer_note' => "Leave at the door.\nThank you!"])->assertStatus(201);

        $mail = $this->mail();

        $this->assertMatchesRegularExpression('/1000 Sofia<br>\s*Sofia-city<br>\s*Bulgaria/', $mail['html']);
        $this->assertStringContainsString('Your note', $mail['html']);
        $this->assertStringContainsString('Leave at the door.<br>', $mail['html']);
        $this->assertStringContainsString('Thank you!', $mail['html']);
        $this->assertStringContainsString('Sofia-city', $mail['text']);
        $this->assertStringContainsString('Leave at the door.', $mail['text']);
    }

    public function test_with_no_region_and_no_note_the_mail_has_neither(): void
    {
        $this->checkout($this->streetFields('BG'))->assertStatus(201);

        $mail = $this->mail();

        $this->assertStringNotContainsString('Your note', $mail['html']);
        $this->assertStringNotContainsString('Your note', $mail['text']);
        $this->assertMatchesRegularExpression('/1000 Sofia<br>\s*Bulgaria/', $mail['html'], 'the city line is followed straight by the country, as it always was');
    }

    public function test_a_script_in_the_note_or_the_region_is_text_in_the_mail(): void
    {
        $this->checkout($this->streetFields('BG', ['region' => 'Re<b>gion']), ['customer_note' => self::SCRIPT])->assertStatus(201);

        $mail = $this->mail();

        $this->assertStringNotContainsString('<script', $mail['html']);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $mail['html']);
        $this->assertStringNotContainsString('Re<b>gion', $mail['html']);
        $this->assertStringContainsString('Re&lt;b&gt;gion', $mail['html']);
    }

    // ---- the order-received page ---------------------------------------------------------------------------------------------------

    public function test_the_received_page_shows_the_region_and_the_note_escaped(): void
    {
        $response = $this->checkout($this->streetFields('BG', ['region' => 'Sofia-city']), ['customer_note' => "Line one\nLine <i>two</i>\n".self::SCRIPT])->assertStatus(201);

        $html = $this->get($response->json('confirmation_url'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/1000 Sofia<\/span>\s*<span class="sf-received-destination-line">Sofia-city<\/span>\s*<span class="sf-received-destination-line">Bulgaria/', $html);
        $this->assertStringContainsString('Your note: Line one', $html);
        $this->assertStringContainsString('Line &lt;i&gt;two&lt;/i&gt;', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_with_no_region_and_no_note_the_received_page_is_what_it_was(): void
    {
        $response = $this->checkout($this->streetFields('BG'))->assertStatus(201);

        $html = $this->get($response->json('confirmation_url'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Your note', $html);
        $this->assertMatchesRegularExpression('/1000 Sofia<\/span>\s*<span class="sf-received-destination-line">Bulgaria/', $html);
    }

    public function test_the_received_page_and_the_mail_are_in_the_store_language(): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        $response = $this->checkout($this->streetFields('BG'), ['customer_note' => 'Моля, звънете'])->assertStatus(201);

        $this->assertStringContainsString('Вашата бележка: Моля, звънете', $this->get($response->json('confirmation_url'))->getContent());
        $this->assertStringContainsString('Вашата бележка', $this->mail()['html']);
    }

    // ---- the admin order view ------------------------------------------------------------------------------------------------------------

    public function test_the_admin_view_shows_the_region_between_the_city_and_the_country_and_the_note_in_its_own_escaped_block(): void
    {
        $this->checkout($this->streetFields('BG', ['region' => 'Sofia-city']), ['customer_note' => "Line one\nLine <b>two</b> ".self::SCRIPT])->assertStatus(201);
        $orderId = DB::table('orders')->sole()->id;
        $this->staff();
        app()->setLocale('en');

        $html = Livewire::test(ViewOrder::class, ['record' => $orderId])->html();

        $this->assertMatchesRegularExpression('/1000 Sofia<br \/>Sofia-city<br \/>BG/', $html);
        $this->assertStringContainsString('Customer note', $html);
        $this->assertStringContainsString('Line one<br />', $html, 'the line breaks are kept (nl2br of the escaped text)');
        $this->assertStringContainsString('Line &lt;b&gt;two&lt;/b&gt; &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }

    public function test_the_admin_view_has_no_note_block_and_no_region_line_without_them(): void
    {
        $this->checkout($this->streetFields('BG'))->assertStatus(201);
        $orderId = DB::table('orders')->sole()->id;
        $this->staff();
        app()->setLocale('en');

        $html = Livewire::test(ViewOrder::class, ['record' => $orderId])->html();

        $this->assertStringNotContainsString('Customer note', $html);
        $this->assertMatchesRegularExpression('/1000 Sofia<br \/>BG/', $html);
    }

    // ---- the admin editor ------------------------------------------------------------------------------------------------------------------

    public function test_the_form_mapper_carries_the_region_and_a_change_of_only_the_region_is_a_change(): void
    {
        $current = ['delivery_type' => 'street_address', 'recipient_first_name' => 'Ana', 'recipient_last_name' => 'Testov', 'phone' => '1', 'country' => 'BG', 'city' => 'Sofia', 'postal_code' => '1000', 'address_line_1' => 'V 1', 'region' => 'Sofia-city'];

        $this->assertNull(OrderEditFormMapper::deliveryChange($current, $current));
        $this->assertNull(OrderEditFormMapper::deliveryChange($current, ['region' => ' Sofia-city '] + $current), 'trimmed: not a change');

        $change = OrderEditFormMapper::deliveryChange($current, ['region' => 'Plovdiv'] + $current);
        $this->assertSame('Plovdiv', $change->region);

        $this->assertNull(OrderEditFormMapper::deliveryChange($current, ['region' => ''] + $current)->region, 'clearing it is a change to none');
    }

    public function test_staff_edit_the_region_of_a_street_order_and_the_note_stays_what_the_customer_wrote(): void
    {
        $this->checkout($this->streetFields('BG', ['region' => 'Sofia-city']), ['customer_note' => 'Please call'])->assertStatus(201);
        $orderId = (string) DB::table('orders')->sole()->id;

        $edit = fn (?string $region, OrderDeliveryType $type = OrderDeliveryType::STREET_ADDRESS, int $revision = 0) => app(OrderEditor::class)->apply(
            $orderId, $revision, [], $type === OrderDeliveryType::STREET_ADDRESS
                ? new OrderDeliveryChange($type, 'Guest', 'Buyer', '+359888000000', 'BG', 'Varna', null, 'Sea 1', null, null, null, null, $region)
                : new OrderDeliveryChange($type, 'Guest', 'Buyer', '+359888000000', 'BG', null, null, null, null, 'econt', 'o-1', 'Varna'),
            OrderPromotionCodeChange::unchanged(), null, null, null, new DateTimeImmutable('2026-10-14 10:00:00'),
        );

        $edit('Varna-region');
        $this->assertSame(['Varna-region', 'Please call'], [DB::table('orders')->value('region'), DB::table('orders')->value('customer_note')]);
        $this->assertSame('Sofia-city', DB::table('order_placement_snapshots')->value('region'), 'the placement snapshot is write-once');

        $edit(null, revision: 1);
        $this->assertSame([null, 'Please call'], [DB::table('orders')->value('region'), DB::table('orders')->value('customer_note')]);

        $edit(null, OrderDeliveryType::PICKUP_POINT, 2);
        $this->assertSame([null, 'Please call', 'pickup_point'], [DB::table('orders')->value('region'), DB::table('orders')->value('customer_note'), DB::table('orders')->value('delivery_type')], 'changing to a pickup point leaves no region; the note is still the customer\'s');
        $this->assertSame('Please call', DB::table('order_placement_snapshots')->value('customer_note'));
    }

    public function test_staff_are_not_bound_by_the_per_country_rule_in_the_edit_form(): void
    {
        // The merchant hid the region in Bulgaria and made it required in the US; the edit form still accepts and keeps a region for BG
        // and a blank one for a US order (that rule governs what a CUSTOMER types).
        $this->regionRule('optional', ['BG' => 'hidden', 'US' => 'required']);
        $this->checkout($this->streetFields('US', ['region' => 'Ohio']))->assertStatus(201);
        $orderId = DB::table('orders')->sole()->id;
        $this->staff();

        $component = Livewire::test(ViewOrder::class, ['record' => $orderId]);
        $component->mountAction(TestAction::make('edit_order')->schemaComponent(true, 'infolist'));
        $this->assertNotEmpty($component->instance()->mountedActions);
        $seeded = $component->instance()->mountedActions[0]['data'];
        $this->assertSame('Ohio', $seeded['delivery']['region'], 'the field exists, prefilled');

        $delivery = $seeded['delivery'];
        $delivery['region'] = '';
        $component->fillForm(['lines' => $seeded['lines'], 'delivery' => $delivery])->callMountedAction();
        $this->assertNull(DB::table('orders')->value('region'), 'a blank region saved for a country where it is "required"');

        $component2 = Livewire::test(ViewOrder::class, ['record' => $orderId]);
        $component2->mountAction(TestAction::make('edit_order')->schemaComponent(true, 'infolist'));
        $seeded = $component2->instance()->mountedActions[0]['data'];
        $delivery = array_merge($seeded['delivery'], ['country' => 'BG', 'region' => 'Sofia-city']);
        $component2->fillForm(['lines' => $seeded['lines'], 'delivery' => $delivery])->callMountedAction();
        $this->assertSame(['BG', 'Sofia-city'], [DB::table('orders')->value('country'), DB::table('orders')->value('region')], 'a region kept for a country where it is "hidden"');
    }

    public function test_the_edit_form_has_no_field_for_the_customers_note(): void
    {
        $this->checkout($this->streetFields('BG'), ['customer_note' => 'Please call'])->assertStatus(201);
        $orderId = DB::table('orders')->sole()->id;
        $this->staff();

        $component = Livewire::test(ViewOrder::class, ['record' => $orderId]);
        $component->mountAction(TestAction::make('edit_order')->schemaComponent(true, 'infolist'));
        $seeded = $component->instance()->mountedActions[0]['data'];

        $this->assertArrayNotHasKey('customer_note', $seeded);
        $this->assertArrayNotHasKey('customer_note', $seeded['delivery']);
    }
}
